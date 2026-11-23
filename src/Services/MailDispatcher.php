<?php

namespace Nfgarching\Mailer\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Mailer\Data\MailDraft;
use Nfgarching\Mailer\Mail\ComposedMail;
use Nfgarching\Mailer\Models\MailLink;
use Nfgarching\Mailer\Models\SentMail;
use Nfgarching\Mailer\Support\SecurityStatus;
use Throwable;

/**
 * Versendet einen Protokolleintrag (Status 'pending'), unabhängig vom Modal:
 * Anhänge prüfen, PIN und Download-Links bzw. Portal-Freigabe erzeugen,
 * ComposedMail bauen und über den Mailer „nfmailer“ senden.
 *
 * Aufrufer: das Modal (sofort) und später der Scheduler (zeitversetzt).
 * Validierung und Berechtigung erledigt der Aufrufer vorher.
 */
class MailDispatcher
{
    public function dispatch(SentMail $log): DispatchResult
    {
        $draft = MailDraft::fromLog($log);
        $files = $draft->activeAttachments();

        // Fehlende Dateien: nicht senden, statt eine Mail ohne den angekündigten Anhang zu verschicken
        $missing = array_values(array_filter($files, fn (array $f) => ! Storage::disk($f['disk'])->exists($f['path'])));
        if ($missing) {
            $error = 'Anhang nicht mehr vorhanden: '.implode(', ', array_column($missing, 'name'));
            $log->update(['status' => SentMail::FAILED, 'error' => $error]);

            return DispatchResult::failed($log, $error);
        }

        $security = SecurityStatus::for($draft->recipients());
        $encrypt = $draft->option('encrypt') && $security['encryptPossible'];
        $asLinks = $draft->option('links') && $files;
        $inner = config('nfmailer.mailer') ?: config('mail.default');

        $log->update([
            'status' => SentMail::PENDING,
            'mailer' => $inner,
            'attachments' => $files,
            'as_links' => (bool) $asLinks,
            'signed' => $security['signs'],
            'encrypted' => $encrypt,
            'archive' => config('nfmailer.archive.enabled', false) && $draft->option('archive'),
            'error' => null,
        ]);

        if ($asLinks && $this->portalAvailable()) {
            return $this->viaPortal($log, $draft, $files, $security, $encrypt);
        }

        return $this->direct($log, $draft, $files, $security, $encrypt, (bool) $asLinks);
    }

    /**
     * Download-Links über das Download-Portal statt der eigenen Links, wenn
     * nfgarching/share installiert und eingerichtet ist (abschaltbar mit
     * nfmailer.links.portal = false).
     */
    public function portalAvailable(): bool
    {
        $client = 'Nfgarching\\Share\\Client\\PortalClient';

        if (! config('nfmailer.links.portal', true) || ! class_exists($client)) {
            return false;
        }

        try {
            return app($client)->isConfigured();
        } catch (Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------- eine Mail an alle

    protected function direct(SentMail $log, MailDraft $draft, array $files, array $security, bool $encrypt, bool $asLinks): DispatchResult
    {
        $queue = (bool) config('nfmailer.queue', false);

        // PIN: beim zeitversetzten Versand schon beim Planen erzeugt (und angezeigt)
        $pin = $asLinks && $draft->option('pin') ? ($log->pin ?: MailLink::generatePin()) : null;
        $log->update(['pin' => $pin]);

        $links = [];
        if ($asLinks) {
            foreach ($files as $f) {
                $link = MailLink::issue($f, $log->id, $pin);
                $links[] = [
                    'name' => $f['name'],
                    'url' => $link->url(),
                    'expires' => $link->expires_at?->format('d.m.Y'),
                ];
            }
        }

        $mail = (new ComposedMail(
            mailSubject: $draft->subject,
            text: $draft->body,
            files: $links ? [] : $files,
            links: $links,
            signature: $this->signature($draft),
            encrypt: $encrypt,
            pinNote: $pin ? 'Zum Abruf benötigen Sie eine PIN, die Sie getrennt von dieser E-Mail erhalten.' : null,
        ))
            ->to($this->addressList($draft->to))
            ->cc($this->addressList($draft->cc))
            ->bcc($this->addressList($draft->bcc))
            ->forLog($log);

        try {
            if ($queue) {
                // Status vorher setzen: Der Listener RecordSentMail zieht ihn nach dem
                // Versand auf „sent“ – bei QUEUE_CONNECTION=sync noch während queue().
                $log->update(['status' => SentMail::QUEUED]);
                Mail::mailer('nfmailer')->queue($mail);
            } else {
                $sent = Mail::mailer('nfmailer')->send($mail);
                $log->update([
                    'status' => SentMail::SENT,
                    'sent_at' => now(),
                    'message_id' => $sent?->getMessageId(),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            $log->update(['status' => SentMail::FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            return DispatchResult::failed($log, 'Versand fehlgeschlagen: '.$e->getMessage());
        }

        $pinMailed = $pin && $draft->option('pin_by_mail') && $this->sendPinMail($draft, $pin);

        return new DispatchResult(
            log: $log->refresh(),
            ok: true,
            pin: $pin,
            pinMailed: $pinMailed,
            signed: $security['signs'],
            encrypted: $encrypt,
        );
    }

    // ---------------------------------------------------------------- Download-Portal

    /**
     * Versand über das Portal: Die Dateien werden einmal bereitgestellt, jeder
     * Empfänger (An, CC und BCC) bekommt einen eigenen Link und eine eigene
     * E-Mail. So lässt sich der Abruf je Person nachvollziehen und sperren;
     * dafür sehen die Empfänger einander nicht.
     */
    protected function viaPortal(SentMail $log, MailDraft $draft, array $files, array $security, bool $encrypt): DispatchResult
    {
        $queue = (bool) config('nfmailer.queue', false);
        $recipients = collect($draft->recipients())
            ->unique(fn ($r) => strtolower($r['email']))
            ->values();

        $log->update(['pin' => null]);

        try {
            $result = app('Nfgarching\\Share\\Services\\ShareService')->share([
                'context' => $draft->context,
                'subject' => $draft->subject,
                'expires_at' => now()->addDays((int) config('nfmailer.links.expires_days', 14))->endOfDay(),
                'max_downloads' => (int) (config('nfmailer.links.max_downloads') ?: config('nfshare.defaults.max_downloads', 5)),
                'recipients' => $recipients->map(fn ($r) => [
                    'email' => $r['email'],
                    'name' => $r['name'] ?? null,
                    'password' => (bool) $draft->option('pin'),
                ])->all(),
                'files' => array_map(fn ($f) => ['disk' => $f['disk'], 'path' => $f['path'], 'name' => $f['name'], 'size' => $f['size'] ?? 0], $files),
            ], $log->user_id);
        } catch (Throwable $e) {
            report($e);
            $message = method_exists($e, 'userMessage') ? $e->userMessage() : $e->getMessage();
            $log->update(['status' => SentMail::FAILED, 'error' => $message]);

            return DispatchResult::failed($log, 'Bereitstellung im Download-Portal fehlgeschlagen: '.$message);
        }

        $shipment = $result->shipment;
        $names = collect($files)->pluck('name');
        $label = $names->count() === 1 ? $names->first() : $names->count().' Dokumente ('.$names->take(3)->implode(', ').($names->count() > 3 ? ', …' : '').')';
        $passwords = [];
        $errors = [];

        foreach ($shipment->recipients as $recipient) {
            $link = $result->links[$recipient->id] ?? null;

            if ($link === null) {
                $errors[] = $recipient->email.': kein Link';

                continue;
            }

            $address = ['email' => $recipient->email, 'name' => (string) ($recipient->name ?? '')];

            $mail = (new ComposedMail(
                mailSubject: $draft->subject,
                text: $draft->body,
                files: [],
                links: [['name' => $label, 'url' => $link['url'], 'expires' => $shipment->expires_at?->timezone(config('app.timezone'))->format('d.m.Y')]],
                signature: $this->signature($draft),
                encrypt: $encrypt,
                pinNote: $link['password'] ? 'Zum Abruf benötigen Sie ein Passwort, das Sie getrennt von dieser E-Mail erhalten.' : null,
            ))->to([$address])->forLog($log);

            try {
                $queue ? Mail::mailer('nfmailer')->queue($mail) : Mail::mailer('nfmailer')->send($mail);
            } catch (Throwable $e) {
                report($e);
                $errors[] = $recipient->email.': '.$e->getMessage();

                continue;
            }

            if ($link['password']) {
                $passwords[] = [
                    'email' => $recipient->email,
                    'name' => $recipient->name,
                    'password' => $link['password'],
                    'mailed' => $draft->option('pin_by_mail') && $this->sendPasswordMail($draft, $address, $link['password']),
                ];
            }
        }

        $log->update($errors
            ? ['status' => SentMail::FAILED, 'error' => implode('; ', $errors)]
            : ['status' => $queue ? SentMail::QUEUED : SentMail::SENT, 'sent_at' => $queue ? null : now()]);

        return new DispatchResult(
            log: $log->refresh(),
            ok: true,
            error: $errors ? 'Nicht an alle versandt: '.implode('; ', $errors) : null,
            portal: true,
            passwords: $passwords,
            errors: $errors,
            signed: $security['signs'],
            encrypted: $encrypt,
        );
    }

    // ---------------------------------------------------------------- Hilfen

    /** Anzuhängende Signatur – null, wenn keine konfiguriert oder der Schalter aus ist */
    public function signature(MailDraft $draft): ?string
    {
        return $draft->option('signature') ? (config('nfmailer.signature') ?: null) : null;
    }

    /** Passwort für den Portal-Link in einer eigenen E-Mail an einen Empfänger */
    protected function sendPasswordMail(MailDraft $draft, array $address, string $password): bool
    {
        $text = "Guten Tag,\n\nfür die Dokumente, die ich Ihnen soeben in einer separaten E-Mail über unser "
            ."Dokumentenportal bereitgestellt habe, lautet das Passwort:\n\n    {$password}\n\n"
            .'Groß- und Kleinschreibung sowie Bindestriche spielen bei der Eingabe keine Rolle.'
            .(($sig = $this->signature($draft)) ? "\n\n--\n{$sig}" : '');

        try {
            Mail::mailer('nfmailer')->raw($text, fn ($m) => $m->to($address['email'], $address['name'])
                ->subject('Passwort für Ihren Dokumentenabruf'));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Zweite E-Mail nur mit der PIN (getrennter Versand, ohne Bezug zum Inhalt im Betreff) */
    protected function sendPinMail(MailDraft $draft, string $pin): bool
    {
        $text = "Guten Tag,\n\nfür die Dokumente, die ich Ihnen soeben in einer separaten E-Mail zum Download "
            ."bereitgestellt habe, lautet die PIN:\n\n    {$pin}\n\n"
            .'Die PIN ist für alle Dokumente dieser E-Mail gültig.'
            .(($sig = $this->signature($draft)) ? "\n\n--\n{$sig}" : '');

        try {
            // Symfony\Address verlangt einen String als Namen (null führt zu TypeError)
            $list = fn (array $rs) => array_map(fn ($r) => ['email' => $r['email'], 'name' => (string) ($r['name'] ?? '')], $rs);

            Mail::mailer('nfmailer')->raw($text, function ($m) use ($list, $draft) {
                $m->to($list($draft->to))
                    ->cc($list($draft->cc))
                    ->bcc($list($draft->bcc))
                    ->subject('PIN für Ihren Dokumentenabruf');
            });

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    protected function addressList(array $recipients): array
    {
        return array_map(fn ($r) => ['email' => $r['email'], 'name' => $r['name'] ?? null], $recipients);
    }
}
