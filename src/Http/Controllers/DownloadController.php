<?php

namespace Nfgarching\Mailer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Mailer\Models\MailLink;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET  zeigt nur eine Seite mit Download-Knopf (und ggf. PIN-Feld).
 *      Virenscanner und Link-Vorschauen (z. B. Outlook Safe Links) rufen Links
 *      automatisch per GET ab – sie sollen weder Downloads verbrauchen noch zählen.
 * POST liefert die Datei nach Prüfung von Ablauf, Sperre, Limit und PIN.
 */
class DownloadController
{
    public function show(string $token): Response
    {
        $link = MailLink::findByToken($token);

        return response()
            ->view('nfmailer::download', [
                'link'   => $link,
                'reason' => $link ? $link->unavailableReason() : 'Der Link ist ungültig.',
                'token'  => $token,
            ], $link ? 200 : 404)
            ->withHeaders($this->noIndex());
    }

    public function download(Request $request, string $token): Response
    {
        $link = MailLink::findByToken($token);

        if (! $link || $link->unavailableReason()) {
            return $this->show($token);
        }

        if ($link->hasPin() && ! $link->checkPin((string) $request->input('pin', ''))) {
            $link->increment('pin_failures');
            $left = (int) config('nfmailer.links.pin_attempts', 5) - $link->pin_failures;

            if ($left <= 0) {
                $link->revoke();
            }

            return redirect()->route('nfmailer.download', $token)->withErrors([
                'pin' => $left > 0
                    ? "Die PIN ist nicht korrekt. Noch {$left} Versuch(e)."
                    : 'Zu viele Fehlversuche – der Link wurde gesperrt. Bitte wenden Sie sich an den Absender.',
            ]);
        }

        $disk = Storage::disk($link->disk);
        abort_unless($disk->exists($link->path), 404, 'Die Datei ist nicht mehr verfügbar.');

        $first = $link->download_count === 0;
        $link->increment('download_count', 1, ['pin_failures' => 0, 'last_downloaded_at' => now()]);

        if ($first) {
            $this->notifySender($link);
        }

        return $disk->download($link->path, $link->name, $this->noIndex() + ['Cache-Control' => 'no-store, private']);
    }

    /** Benachrichtigt den Absender beim ersten Abruf einer Datei. */
    protected function notifySender(MailLink $link): void
    {
        if (! config('nfmailer.links.notify_on_download', true) || $link->notified_at) {
            return;
        }

        try {
            $mail = $link->sentMail;
            $userModel = config('auth.providers.users.model');
            $user = $mail?->user_id && $userModel ? $userModel::find($mail->user_id) : null;

            if (! $user?->email) {
                return;
            }

            $to = collect($mail->to)->pluck('email')->implode(', ');
            $text = "Die Datei „{$link->name}“ wurde soeben erstmals heruntergeladen.\n\n"
                . "E-Mail: {$mail->subject}\n"
                . "Empfänger: {$to}\n"
                . 'Versandt: ' . ($mail->sent_at?->format('d.m.Y H:i') ?? '–') . "\n"
                . 'Abruf: ' . now()->format('d.m.Y H:i') . "\n";

            Mail::raw($text, fn ($m) => $m->to($user->email)->subject('Download: ' . $link->name));

            $link->forceFill(['notified_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function noIndex(): array
    {
        return ['X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
    }
}
