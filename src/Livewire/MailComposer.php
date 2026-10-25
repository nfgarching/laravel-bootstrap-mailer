<?php

namespace Nfgarching\Mailer\Livewire;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Nfgarching\Mailer\Data\Recipient;
use Nfgarching\Mailer\Mail\ComposedMail;
use Nfgarching\Mailer\Models\MailLink;
use Nfgarching\Mailer\Models\SentMail;
use Nfgarching\Mailer\Sources\RecipientResolver;
use Nfgarching\Mailer\Support\AddressParser;
use Nfgarching\Mailer\Support\AttachmentGuard;
use Throwable;

/**
 * Empfänger-Modal mit An/CC/BCC, Suche, Mehrfachauswahl, freier Eingabe,
 * Anhängen bzw. Download-Links und Versandprotokoll.
 *
 * Öffnen:   Livewire.dispatch('nfmailer-open', { options: {...} })
 *           $this->dispatch('nfmailer-open', options: [...])
 * Danach:   Event 'nfmailer-sent' mit { id } (ID des Protokolleintrags)
 */
class MailComposer extends Component
{
    use WithFileUploads;

    public const FIELDS = ['to' => 'An', 'cc' => 'CC', 'bcc' => 'BCC'];

    /** @var list<array{email: string, name: ?string, hint: ?string, source: ?string}> */
    public array $to = [];
    public array $cc = [];
    public array $bcc = [];

    public string $activeField = 'to';
    public string $search = '';
    public string $freeInput = '';

    /** Markierte E-Mails in der Auswahlliste */
    public array $picked = [];

    public string $subject = '';
    public string $body = '';

    #[Locked]
    public array $context = [];

    /** Übergebene (geprüfte) Anhänge */
    #[Locked]
    public array $attachments = [];

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];
    public array $newUploads = [];

    public bool $asLinks = false;

    public ?string $notice = null;

    // ---------------------------------------------------------------- Öffnen

    #[On('nfmailer-open')]
    public function open(array $options = []): void
    {
        $this->reset();
        $this->resetValidation();
        unset($this->suggestions, $this->results);

        $this->context = (array) ($options['context'] ?? []);
        $this->subject = (string) ($options['subject'] ?? '');
        $this->body    = (string) ($options['body'] ?? '');
        $this->asLinks = (bool) ($options['links'] ?? config('nfmailer.links.default', false));

        foreach (array_keys(self::FIELDS) as $field) {
            foreach ((array) ($options[$field] ?? []) as $value) {
                if ($r = AddressParser::one($value)) {
                    $this->put($field, $r);
                }
            }
        }

        foreach ((array) ($options['attachments'] ?? []) as $a) {
            if (is_array($a) && $checked = AttachmentGuard::check($a)) {
                $this->attachments[] = $checked;
            }
        }

        $this->dispatch('nfmailer-show');
    }

    // ---------------------------------------------------------------- Empfänger

    public function setActive(string $field): void
    {
        if (isset(self::FIELDS[$field])) {
            $this->activeField = $field;
        }
    }

    /** Klick auf einen Eintrag der Liste */
    public function add(string $email, ?string $field = null): void
    {
        $field = isset(self::FIELDS[$field]) ? $field : $this->activeField;
        $r = $this->lookup($email) ?? AddressParser::one($email);

        if ($r) {
            $this->put($field, $r);
        }
    }

    /** Alle markierten Einträge in ein Feld übernehmen */
    public function addPicked(string $field): void
    {
        if (! isset(self::FIELDS[$field])) {
            return;
        }

        foreach ($this->picked as $email) {
            if ($r = $this->lookup($email) ?? AddressParser::one($email)) {
                $this->put($field, $r);
            }
        }

        $this->picked = [];
    }

    /** Freie Eingabe (Enter, Komma, Semikolon, Einfügen, Verlassen des Feldes) */
    public function addFree(): void
    {
        if (trim($this->freeInput) === '') {
            return;
        }

        ['valid' => $valid, 'invalid' => $invalid] = AddressParser::parse($this->freeInput);

        foreach ($valid as $r) {
            // Name aus Adressbuch ergänzen, falls nur die Adresse eingetippt wurde
            $known = $r->name ? null : $this->lookup($r->email, true);
            $this->put($this->activeField, $known ?? $r);
        }

        $this->freeInput = implode(', ', $invalid);
        $this->resetErrorBag('freeInput');

        if ($invalid) {
            $this->addError('freeInput', 'Keine gültige E-Mail-Adresse: ' . implode(', ', $invalid));
        }
    }

    public function remove(string $field, string $email): void
    {
        if (isset(self::FIELDS[$field])) {
            $this->{$field} = array_values(array_filter(
                $this->{$field},
                fn ($r) => mb_strtolower($r['email']) !== mb_strtolower($email),
            ));
        }
    }

    /** Backspace im leeren Eingabefeld */
    public function removeLast(): void
    {
        array_pop($this->{$this->activeField});
    }

    public function move(string $from, string $email, string $to): void
    {
        if (! isset(self::FIELDS[$from], self::FIELDS[$to])) {
            return;
        }

        foreach ($this->{$from} as $r) {
            if (mb_strtolower($r['email']) === mb_strtolower($email)) {
                $this->put($to, AddressParser::one($r));

                return;
            }
        }
    }

    /**
     * Fügt einen Empfänger hinzu. Eine Adresse steht immer nur in einem Feld –
     * steht sie schon woanders, wird sie dorthin verschoben.
     */
    protected function put(string $field, ?Recipient $r): void
    {
        if (! $r || ! isset(self::FIELDS[$field])) {
            return;
        }

        foreach (array_keys(self::FIELDS) as $f) {
            $this->remove($f, $r->email);
        }

        $this->{$field}[] = $r->toArray();
        $this->resetErrorBag('to');
    }

    /** Sucht eine Adresse in Vorschlägen und aktuellen Treffern (für Name/Hinweis). */
    protected function lookup(string $email, bool $searchSources = false): ?Recipient
    {
        $key = mb_strtolower(trim($email));

        $pool = collect($this->suggestions)->flatten(1)->merge($this->results);

        if ($searchSources) {
            $pool = $pool->merge(app(RecipientResolver::class)->search($key, $this->context));
        }

        $hit = $pool->first(fn ($r) => mb_strtolower($r['email']) === $key);

        return $hit ? AddressParser::one($hit) : null;
    }

    // ---------------------------------------------------------------- Listen

    #[Computed]
    public function suggestions(): array
    {
        return app(RecipientResolver::class)->suggestions($this->context);
    }

    #[Computed]
    public function results(): array
    {
        $term = trim($this->search);

        if (mb_strlen($term) < (int) config('nfmailer.search.min_chars', 2)) {
            return [];
        }

        return app(RecipientResolver::class)->search($term, $this->context);
    }

    /** [email => Feld] aller bereits gewählten Adressen */
    #[Computed]
    public function assigned(): array
    {
        $map = [];

        foreach (array_keys(self::FIELDS) as $f) {
            foreach ($this->{$f} as $r) {
                $map[mb_strtolower($r['email'])] = $f;
            }
        }

        return $map;
    }

    public function updatedSearch(): void
    {
        $this->picked = [];
    }

    // ---------------------------------------------------------------- Anhänge

    public function updatedNewUploads(): void
    {
        $this->validate(
            ['newUploads.*' => 'file|max:' . (int) config('nfmailer.uploads.max_kb', 20480)],
            ['newUploads.*.max' => 'Die Datei ist zu groß.'],
        );

        $this->uploads = array_merge($this->uploads, $this->newUploads);
        $this->newUploads = [];
    }

    public function removeAttachment(int $i): void
    {
        unset($this->attachments[$i]);
        $this->attachments = array_values($this->attachments);
    }

    public function removeUpload(int $i): void
    {
        unset($this->uploads[$i]);
        $this->uploads = array_values($this->uploads);
    }

    // ---------------------------------------------------------------- Versand

    public function send(): void
    {
        ($gate = config('nfmailer.gate'))
            ? Gate::authorize($gate)
            : abort_unless(auth()->check(), 403);

        $this->notice = null;
        $this->addFree();

        if ($this->getErrorBag()->has('freeInput')) {
            return;
        }

        $this->validate([
            'to'      => 'required|array|min:1',
            'subject' => 'required|string|max:255',
            'body'    => 'required|string',
        ], [
            'to.required'      => 'Bitte mindestens einen Empfänger unter „An“ angeben.',
            'to.min'           => 'Bitte mindestens einen Empfänger unter „An“ angeben.',
            'subject.required' => 'Bitte einen Betreff eingeben.',
            'body.required'    => 'Bitte einen Text eingeben.',
        ]);

        // Hochgeladene Dateien dauerhaft speichern
        $files = $this->attachments;
        $disk = config('nfmailer.uploads.disk', 'local');

        foreach ($this->uploads as $upload) {
            $files[] = [
                'disk'   => $disk,
                'path'   => $upload->store(config('nfmailer.uploads.directory', 'nfmailer/uploads'), $disk),
                'name'   => $upload->getClientOriginalName(),
                'size'   => $upload->getSize(),
                'origin' => 'upload',
            ];
        }

        $mailer = config('nfmailer.mailer');
        $queue  = (bool) config('nfmailer.queue', false);

        $log = SentMail::create([
            'user_id'     => auth()->id(),
            'status'      => 'pending',
            'mailer'      => $mailer,
            'subject'     => $this->subject,
            'body'        => $this->body,
            'to'          => $this->to,
            'cc'          => $this->cc,
            'bcc'         => $this->bcc,
            'attachments' => $files,
            'as_links'    => $this->asLinks && $files,
            'context'     => $this->context,
            'context_key' => SentMail::contextKey($this->context),
        ]);

        $links = [];
        if ($this->asLinks && $files) {
            foreach ($files as $f) {
                $link = MailLink::issue($f, $log->id);
                $links[] = [
                    'name'    => $f['name'],
                    'url'     => $link->url(),
                    'expires' => $link->expires_at?->format('d.m.Y'),
                ];
            }
        }

        $mail = (new ComposedMail(
            mailSubject: $this->subject,
            text: $this->body,
            files: $links ? [] : $files,
            links: $links,
            signature: config('nfmailer.signature') ?: null,
        ))
            ->to($this->addressList($this->to))
            ->cc($this->addressList($this->cc))
            ->bcc($this->addressList($this->bcc));

        try {
            if ($queue) {
                Mail::mailer($mailer)->queue($mail);
                $log->update(['status' => 'queued']);
            } else {
                $sent = Mail::mailer($mailer)->send($mail);
                $log->update([
                    'status'     => 'sent',
                    'sent_at'    => now(),
                    'message_id' => $sent?->getMessageId(),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            $log->update(['status' => 'failed', 'error' => $e->getMessage()]);
            $this->notice = 'Versand fehlgeschlagen: ' . $e->getMessage();

            return;
        }

        $this->dispatch('nfmailer-sent', id: $log->id);
        $this->dispatch('nfmailer-hide');
        $this->reset();
    }

    protected function addressList(array $recipients): array
    {
        return array_map(fn ($r) => ['email' => $r['email'], 'name' => $r['name']], $recipients);
    }

    public function render()
    {
        return view('nfmailer::livewire.mail-composer', [
            'fields' => self::FIELDS,
        ]);
    }
}
