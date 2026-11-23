<?php

namespace Nfgarching\Mailer\Livewire;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Nfgarching\Core\Traits\TranscribesDictation;
use Nfgarching\Mailer\Data\MailDraft;
use Nfgarching\Mailer\Data\Recipient;
use Nfgarching\Mailer\Exceptions\DraftConflict;
use Nfgarching\Mailer\Models\MailFavorite;
use Nfgarching\Mailer\Models\SentMail;
use Nfgarching\Mailer\Security\CertificateDirectory;
use Nfgarching\Mailer\Security\CertificateStore;
use Nfgarching\Mailer\Services\DraftStore;
use Nfgarching\Mailer\Services\MailDispatcher;
use Nfgarching\Mailer\Sources\AttachmentResolver;
use Nfgarching\Mailer\Sources\RecipientResolver;
use Nfgarching\Mailer\Support\AddressParser;
use Nfgarching\Mailer\Support\AttachmentGuard;
use Nfgarching\Mailer\Support\MailMarkdown;
use Nfgarching\Mailer\Support\SecurityStatus;
use Nfgarching\Textbot\Enhancers\EnhancerFactory;
use Nfgarching\Textbot\Exceptions\TextEnhancementException;
use Nfgarching\Textbot\Models\TextUsage;
use Nfgarching\Textbot\Services\AiSettings;
use Throwable;

/**
 * Empfänger-Modal mit An/CC/BCC, Suche, Mehrfachauswahl, freier Eingabe,
 * Anhängen bzw. Download-Links und Versandprotokoll.
 *
 * Öffnen:   Livewire.dispatch('nfmailer-open', { options: {...} })
 *           $this->dispatch('nfmailer-open', options: [...])
 * Seite:    route('nfmailer.compose', [...]) als Popup oder Tab (Query wie options)
 * Entwurf:  Option 'draft' => ID öffnet einen gespeicherten Entwurf,
 *           'duplicate' => ID legt eine Kopie (Entwurf oder versandte Mail) als neuen Entwurf an
 * Danach:   Event 'nfmailer-sent' mit { id } (ID des Protokolleintrags)
 *
 * Der Versand selbst liegt im MailDispatcher; das Modal sammelt nur den Inhalt
 * (MailDraft), speichert ihn als Entwurf bzw. übergibt ihn zum Versand.
 */
#[Layout('layout::popup')]
class MailComposer extends Component
{
    use TranscribesDictation;
    use WithFileUploads;

    public const FIELDS = ['to' => 'An', 'cc' => 'CC', 'bcc' => 'BCC'];

    /** @var list<array{email: string, name: ?string, hint: ?string, source: ?string}> */
    public array $to = [];

    public array $cc = [];

    public array $bcc = [];

    public string $activeField = 'to';

    /** CC/BCC-Zeilen eingeblendet (belegte Felder werden immer gezeigt) */
    public bool $showCopies = false;

    public string $search = '';

    public string $freeInput = '';

    /** Markierte E-Mails in der Auswahlliste */
    public array $picked = [];

    public string $subject = '';

    /** Nachricht in Markdown (HTML-Teil: MailMarkdown, Text-Teil: unverändert) */
    public string $body = '';

    /** Nachricht formatiert anzeigen statt bearbeiten */
    public bool $preview = false;

    #[Locked]
    public array $context = [];

    /**
     * Geprüfte Anhänge; 'active' = wird mitgesendet. Übergebene Anhänge (origin 'preset')
     * bleiben beim Abwählen in der Liste, damit sie wieder aktiviert werden können.
     */
    #[Locked]
    public array $attachments = [];

    /** Überschrift der übergebenen Anhänge in der rechten Spalte (Option attachments_label) */
    #[Locked]
    public string $attachmentsLabel = '';

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    public array $newUploads = [];

    /** Offcanvas „Anhänge“ geöffnet – nur dann werden die Anhang-Quellen abgefragt */
    public bool $showSources = false;

    public string $sourceSearch = '';

    /** Markierte Dateien der Anhang-Quellen (Schlüssel aus AttachmentResolver) */
    public array $pickedFiles = [];

    public bool $asLinks = false;

    /** Download-Links mit PIN schützen */
    public bool $usePin = true;

    /** PIN zusätzlich in einer zweiten E-Mail senden */
    public bool $pinByMail = false;

    /** S/MIME-Verschlüsselung (nur wirksam, wenn alle Empfänger ein Zertifikat haben) */
    public bool $encrypt = true;

    /** Gesendete Mail ablegen (nfmailer.archive; die Anwendung wertet MailSent aus) */
    public bool $archive = false;

    /** Signatur (nfmailer.signature) anhängen; Vorgabe nfmailer.signature_default */
    public bool $useSignature = true;

    /** Ergebnis der letzten Zertifikatssuche in den CA-Verzeichnissen, je Adresse eine Zeile */
    public array $lookupResults = [];

    /** Ergebnis nach Versand mit PIN (Modal bleibt offen, damit die PIN sichtbar ist) */
    public ?array $result = null;

    public ?string $notice = null;

    /** KI-Einstellungen für <x-ai-button> (textbot); bleiben über resetExcept() hinweg erhalten */
    public string $mode = '';

    public string $provider = '';

    public string $aimodel = '';

    /** Letzter KI-Lauf dieser Mail (textbot_usages) für die Verbrauchsanzeige */
    #[Locked]
    public ?int $aiUsageId = null;

    /** true = eigenständige Seite statt Modal */
    #[Locked]
    public bool $page = false;

    /** Gespeicherter Entwurf (sent_mails, status 'draft'), null = noch nicht gespeichert */
    #[Locked]
    public ?int $draftId = null;

    /** Version des Entwurfs beim Laden/Speichern – Schutz gegen Überschreiben aus einem anderen Fenster */
    #[Locked]
    public int $draftVersion = 0;

    /** Fingerabdruck des zuletzt gespeicherten (bzw. geöffneten) Inhalts */
    #[Locked]
    public string $savedHash = '';

    /** Uhrzeit der letzten Speicherung für die Anzeige im Footer */
    public ?string $savedAt = null;

    /** Konflikt beim Speichern: 'changed' (anderes Fenster) oder 'gone' (versendet/gelöscht) */
    #[Locked]
    public ?string $draftConflict = null;

    /** Aus dem Entwurf entfernte, bereits gespeicherte Uploads – beim nächsten Speichern löschen */
    #[Locked]
    public array $discardedUploads = [];

    // ---------------------------------------------------------------- Öffnen

    /**
     * Als eigenständige Seite (Route nfmailer.compose, Popup oder Tab):
     * Optionen kommen als Query-Parameter, Arrays JSON-kodiert (wie bei x-button.dispatch).
     */
    public function mount(): void
    {
        $this->initAiSettings();

        if (! request()->routeIs('nfmailer.compose')) {
            return;
        }

        $options = collect(request()->only(['context', 'to', 'cc', 'bcc', 'subject', 'body', 'links', 'pin', 'encrypt', 'archive', 'signature', 'attachments', 'attachments_label', 'draft', 'duplicate']))
            ->map(fn ($v) => is_string($v) && json_validate($v) && is_array($decoded = json_decode($v, true)) ? $decoded : $v)
            ->all();

        $this->open($options);
        $this->page = true;
    }

    /** $data: Payload von x-button.dispatch (Livewire.dispatch(..., { data: {...} })) */
    #[On('nfmailer-open')]
    public function open(array $options = [], array $data = []): void
    {
        $options = $options ?: $data;

        $this->resetExcept('page', 'mode', 'provider', 'aimodel');
        $this->resetValidation();
        unset($this->suggestions, $this->results);

        // Gespeicherten Entwurf öffnen bzw. Kopie als neuen Entwurf anlegen
        if (($id = $options['draft'] ?? $options['duplicate'] ?? null) && auth()->check()) {
            $this->loadDraft((int) $id, copy: ! isset($options['draft']));
            $this->dispatch('nfmailer-show');

            return;
        }

        $this->context = (array) ($options['context'] ?? []);
        $this->subject = (string) ($options['subject'] ?? '');
        $this->body = (string) ($options['body'] ?? '');
        $this->asLinks = (bool) ($options['links'] ?? config('nfmailer.links.default', false));
        $this->usePin = (bool) ($options['pin'] ?? config('nfmailer.links.pin_default', true));
        $this->encrypt = (bool) ($options['encrypt'] ?? true);
        $this->archive = (bool) ($options['archive'] ?? config('nfmailer.archive.default', true));
        $this->useSignature = (bool) ($options['signature'] ?? config('nfmailer.signature_default', true));

        foreach (array_keys(self::FIELDS) as $field) {
            foreach ((array) ($options[$field] ?? []) as $value) {
                if ($r = AddressParser::one($value)) {
                    $this->put($field, $r);
                }
            }
        }

        // Übergebene Anhänge stehen rechts zur Auswahl, zunächst alle aktiv
        foreach ((array) ($options['attachments'] ?? []) as $a) {
            if (is_array($a) && $checked = AttachmentGuard::check($a)) {
                $this->attachments[] = [...$checked, 'active' => true];
            }
        }
        $this->attachmentsLabel = (string) ($options['attachments_label'] ?? '');

        // Ausgangszustand gilt als „gespeichert“: Autosave erst nach einer Änderung
        $this->savedHash = $this->currentHash();

        $this->dispatch('nfmailer-show');
    }

    // ---------------------------------------------------------------- Empfänger

    /** CC/BCC einblenden (sonst nur sichtbar, wenn belegt) und CC aktivieren */
    public function showCopyFields(): void
    {
        $this->showCopies = true;
        $this->setActive('cc');
    }

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
            $this->addError('freeInput', 'Keine gültige E-Mail-Adresse: '.implode(', ', $invalid));
        }
    }

    /** Auswahl aus dem Dropdown unter dem Eingabefeld */
    public function addInline(string $email): void
    {
        $hit = collect($this->inlineResults)
            ->first(fn ($r) => mb_strtolower($r['email']) === mb_strtolower($email));

        if ($r = AddressParser::one($hit ?? $email)) {
            $this->put($this->activeField, $r);
        }

        // Treffer neu berechnen (sonst rendert der gemerkte Computed-Wert das Dropdown erneut)
        $this->freeInput = '';
        unset($this->inlineResults);
        $this->resetErrorBag('freeInput');
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

    // ---------------------------------------------------------------- Zuordnung

    /**
     * Kopfanzeige der Zuordnung aus dem Kontext, z. B. „Akte #12 · Dokument #34“.
     * Wird die Mail abgelegt und fehlt der Schlüssel aus nfmailer.archive.into im
     * Kontext, legt die Anwendung einen neuen Datensatz an: „neu: Dokument“.
     */
    #[Computed]
    public function contextLabel(): ?string
    {
        $parts = collect($this->context)
            ->filter(fn ($value) => is_scalar($value) && $value !== '')
            ->map(fn ($value, $key) => Str::headline((string) $key).' #'.$value)
            ->values();

        $into = (string) config('nfmailer.archive.into', '');
        if ($into !== '' && config('nfmailer.archive.enabled', false) && $this->archive && ! isset($this->context[$into])) {
            $parts->push('neu: '.Str::headline($into));
        }

        return $parts->isEmpty() ? null : $parts->implode(' · ');
    }

    // ---------------------------------------------------------------- Favoriten

    /** Persönliche Favoriten des Users: [email => true] (für den Stern in der Liste) */
    #[Computed]
    public function favoriteKeys(): array
    {
        if (! auth()->id()) {
            return [];
        }

        return MailFavorite::query()
            ->where('user_id', auth()->id())
            ->pluck('email')
            ->mapWithKeys(fn ($e) => [mb_strtolower($e) => true])
            ->all();
    }

    /** Stern: Adresse zu den persönlichen Favoriten hinzufügen bzw. daraus entfernen. */
    public function toggleFavorite(string $email): void
    {
        $key = mb_strtolower(trim($email));

        if (! auth()->id() || ! filter_var($key, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            return;
        }

        $existing = MailFavorite::query()->where('user_id', auth()->id())->where('email', $key)->first();

        if ($existing) {
            $existing->delete();
        } else {
            $r = $this->lookup($key);
            MailFavorite::create([
                'user_id' => auth()->id(),
                'email' => $key,
                'name' => $r?->name,
                'hint' => $r?->hint,
            ]);
        }

        unset($this->favoriteKeys, $this->suggestions, $this->results);
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

    /** Treffer für das Dropdown unter dem Eingabefeld (ohne bereits gewählte Adressen) */
    #[Computed]
    public function inlineResults(): array
    {
        $term = trim($this->freeInput);

        // Mehrere Adressen (Einfügen) oder zu kurzer Begriff: kein Dropdown
        if (mb_strlen($term) < (int) config('nfmailer.search.min_chars', 2) || preg_match('/[,;]/', $term)) {
            return [];
        }

        $assigned = $this->assigned;

        return array_values(array_filter(
            app(RecipientResolver::class)->search($term, $this->context),
            fn ($r) => ! isset($assigned[mb_strtolower($r['email'])]),
        ));
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

    // ---------------------------------------------------------------- Sicherheit

    /**
     * Stand von Signatur und Verschlüsselung für die aktuelle Empfängerliste.
     *
     * @return array{signs: bool, canEncrypt: bool, encryptPossible: bool, canLookup: bool, missing: list<string>, certs: array<string, bool>}
     */
    #[Computed]
    public function security(): array
    {
        $status = SecurityStatus::for([...$this->to, ...$this->cc, ...$this->bcc]);

        return $status + ['canLookup' => $status['canEncrypt'] && app(CertificateDirectory::class)->enabled()];
    }

    /**
     * Fehlende Empfänger-Zertifikate in den öffentlichen CA-Verzeichnissen suchen.
     * Nur auf Knopfdruck: die Adressen gehen dabei an alle eingetragenen Verzeichnisse.
     */
    public function lookupCertificates(): void
    {
        $directory = app(CertificateDirectory::class);
        if (! $directory->enabled()) {
            return;
        }

        $store = app(CertificateStore::class);
        $this->lookupResults = [];

        foreach (array_keys(array_filter($this->security['certs'], fn ($ok) => ! $ok)) as $email) {
            try {
                $info = $store->importFromDirectory($email, $directory)['imported'];
                $this->lookupResults[] = $info
                    ? "{$email}: Zertifikat übernommen ({$info['issuer']}, gültig bis {$info['valid_to']})"
                    : "{$email}: in keinem Verzeichnis gefunden";
            } catch (Throwable $e) {
                $this->lookupResults[] = "{$email}: gefunden, aber nicht übernommen – {$e->getMessage()}";
            }
        }

        unset($this->security);
    }

    /** Neue Mail nach Versand mit PIN-Anzeige */
    public function closeResult(): void
    {
        $this->result = null;
        $this->dispatch('nfmailer-hide');
        $this->resetExcept('page', 'mode', 'provider', 'aimodel');
    }

    // ---------------------------------------------------------------- Anhänge

    public function updatedNewUploads(): void
    {
        $this->validate(
            ['newUploads.*' => 'file|max:'.(int) config('nfmailer.uploads.max_kb', 20480)],
            ['newUploads.*.max' => 'Die Datei ist zu groß.'],
        );

        $this->uploads = array_merge($this->uploads, $this->newUploads);
        $this->newUploads = [];
    }

    // ---------------------------------------------------------------- KI & Diktat (textbot)

    /** KI-Bearbeitung und Diktat nur, wenn das textbot-Package installiert ist */
    /** Vorschau der Nachricht, wie sie im HTML-Teil der Mail erscheint */
    #[Computed]
    public function previewHtml(): string
    {
        return MailMarkdown::html($this->body);
    }

    #[Computed]
    public function textbotAvailable(): bool
    {
        return class_exists(EnhancerFactory::class);
    }

    /** Modi des Textbots für <x-ai-button> */
    #[Computed]
    public function aiModes(): array
    {
        return $this->textbotAvailable ? app(AiSettings::class)->modes() : [];
    }

    /** Provider und Modelle des Textbots für <x-ai-button> */
    #[Computed]
    public function aiProviders(): array
    {
        return $this->textbotAvailable ? app(AiSettings::class)->providers() : [];
    }

    /** Verbrauch des letzten KI-Laufs für <x-ai-button>; nur der Lauf, eine Mail hat keine Versionen */
    #[Computed]
    public function aiUsage(): array
    {
        if (! $this->aiUsageId || ! $this->textbotAvailable) {
            return [];
        }

        $usage = TextUsage::find($this->aiUsageId);

        return $usage ? ['last' => TextUsage::summary([$usage])] : [];
    }

    /** Startwerte: Standardmodus des Textbots mit dessen Provider/Modell */
    protected function initAiSettings(): void
    {
        if (! $this->textbotAvailable || $this->mode !== '') {
            return;
        }

        $this->mode = app(AiSettings::class)->defaultMode();
        $this->updatedMode($this->mode);
    }

    /** Ein Moduswechsel zieht Provider und Modell des Modus nach (wie im Textbot) */
    public function updatedMode(string $value): void
    {
        if (! $this->textbotAvailable || ! array_key_exists($value, $this->aiModes)) {
            return;
        }

        ['provider' => $this->provider, 'aimodel' => $this->aimodel] = app(AiSettings::class)->defaultsFor($value);
    }

    public function updatedProvider(string $value): void
    {
        if ($this->textbotAvailable) {
            $this->aimodel = app(AiSettings::class)->defaultModelFor($value);
        }
    }

    /** Nachrichtentext per KI bearbeiten – mit Modus, Provider und Modell aus <x-ai-button> */
    public function enhance(): void
    {
        $this->notice = null;

        if (! $this->textbotAvailable) {
            return;
        }

        if (trim($this->body) === '') {
            $this->notice = 'Bitte zuerst einen Text eingeben.';

            return;
        }

        $settings = app(AiSettings::class);
        $ai = $settings->resolve($this->provider, $this->aimodel, $this->mode);

        try {
            ['text' => $this->body, 'usage' => $usage] = $settings->run($this->body, $ai, 'mailer');
            $this->aiUsageId = $usage->id;
            unset($this->aiUsage);
        } catch (TextEnhancementException $e) {
            $this->notice = $e->getMessage();
        }
    }

    /** Diktat (core TranscribesDictation): Meldungen im Hinweisfeld */
    protected function dictationFailed(?string $message): void
    {
        $this->notice = $message;
    }

    /** Offcanvas „Anhänge“ geöffnet: Dateien der Anhang-Quellen laden */
    public function openSources(): void
    {
        $this->showSources = true;
        $this->sourceSearch = '';
        $this->pickedFiles = [];
    }

    /** Offcanvas „Anhänge“ geschlossen: Auswahl verwerfen, Quellen nicht mehr abfragen */
    public function closeSources(): void
    {
        $this->showSources = false;
        $this->sourceSearch = '';
        $this->pickedFiles = [];
    }

    /** Dateien der Anhang-Quellen, nach Suchbegriff gefiltert (nur bei geöffneter Auswahl) */
    #[Computed]
    public function sourceFiles(): array
    {
        if (! $this->showSources || ! $this->context) {
            return [];
        }

        $groups = app(AttachmentResolver::class)->items($this->context);
        $term = mb_strtolower(trim($this->sourceSearch));

        if ($term === '') {
            return $groups;
        }

        return array_filter(array_map(
            fn (array $items) => array_values(array_filter(
                $items,
                fn (array $f) => str_contains(mb_strtolower($f['name'].' '.$f['group']), $term),
            )),
            $groups,
        ));
    }

    /** Markierte Dateien anhängen; die Pfade ermittelt die Quelle, nicht der Browser */
    public function addSourceFiles(): void
    {
        $resolver = app(AttachmentResolver::class);

        foreach ($this->pickedFiles as $key) {
            if (! $file = $resolver->resolve((string) $key, $this->context)) {
                continue;
            }

            // Schon vorhanden (z. B. abgewählter übergebener Anhang): wieder aktivieren
            $i = collect($this->attachments)->search(fn ($a) => $a['disk'] === $file['disk'] && $a['path'] === $file['path']);

            if ($i === false) {
                $this->attachments[] = [...$file, 'active' => true];
            } else {
                $this->attachments[$i]['active'] = true;
            }
        }

        $this->closeSources();
        $this->dispatch('nfmailer-sources-hide');
    }

    /** Checkbox eines übergebenen Anhangs in der rechten Spalte */
    public function toggleAttachment(int $i): void
    {
        if (isset($this->attachments[$i])) {
            $this->attachments[$i]['active'] = ! ($this->attachments[$i]['active'] ?? true);
        }
    }

    /** Alle übergebenen Anhänge aktivieren bzw. abwählen */
    public function setPresetAttachments(bool $active): void
    {
        foreach ($this->attachments as $i => $a) {
            if ($a['origin'] === 'preset') {
                $this->attachments[$i]['active'] = $active;
            }
        }
    }

    /** Übergebene Anhänge werden nur abgewählt, andere entfernt */
    public function removeAttachment(int $i): void
    {
        if (($this->attachments[$i]['origin'] ?? null) === 'preset') {
            $this->attachments[$i]['active'] = false;

            return;
        }

        // Bereits gespeicherter Upload eines Entwurfs: Datei beim nächsten Speichern löschen
        if (($this->attachments[$i]['origin'] ?? null) === 'upload') {
            $this->discardedUploads[] = ['disk' => $this->attachments[$i]['disk'], 'path' => $this->attachments[$i]['path']];
        }

        unset($this->attachments[$i]);
        $this->attachments = array_values($this->attachments);
    }

    /** Anhänge, die mitgesendet werden (ohne das Auswahl-Flag) */
    protected function activeAttachments(): array
    {
        return collect($this->attachments)
            ->filter(fn ($a) => $a['active'] ?? true)
            ->map(fn ($a) => collect($a)->except('active')->all())
            ->values()
            ->all();
    }

    public function removeUpload(int $i): void
    {
        unset($this->uploads[$i]);
        $this->uploads = array_values($this->uploads);
    }

    // ---------------------------------------------------------------- Versand

    public function send(): void
    {
        abort_unless(auth()->check(), 403);
        Gate::authorize('create', SentMail::class);

        $this->notice = null;
        $this->addFree();

        if ($this->getErrorBag()->has('freeInput')) {
            return;
        }

        $this->validate([
            'to' => 'required|array|min:1',
            'subject' => 'required|string|max:255',
            'body' => 'required|string',
        ], [
            'to.required' => 'Bitte mindestens einen Empfänger unter „An“ angeben.',
            'to.min' => 'Bitte mindestens einen Empfänger unter „An“ angeben.',
            'subject.required' => 'Bitte einen Betreff eingeben.',
            'body.required' => 'Bitte einen Text eingeben.',
        ]);

        if ($this->draftId && $existing = SentMail::find($this->draftId)) {
            Gate::authorize('send', $existing);
        }

        $this->storeUploads();
        $store = app(DraftStore::class);

        try {
            $log = $store->claimForSending(
                $this->currentDraft(),
                $this->draftId,
                $this->draftVersion ?: null,
                auth()->id(),
            );
        } catch (DraftConflict $e) {
            $this->handleConflict($e);

            return;
        }

        $store->discardUploads($this->discardedUploads);
        $this->discardedUploads = [];

        // Ab hier ist der Datensatz kein Entwurf mehr
        $this->draftId = null;
        $this->draftVersion = 0;

        $result = app(MailDispatcher::class)->dispatch($log);
        $this->dispatch('nfmailer-drafts-changed');

        if (! $result->ok) {
            // Inhalt bleibt im Modal; das nächste Speichern legt einen neuen Entwurf an
            $this->notice = $result->error;
            $this->savedHash = '';

            return;
        }

        $this->dispatch('nfmailer-sent', id: $result->log->id);

        if ($result->error) {
            $this->notice = $result->error;
        }

        if ($result->needsConfirmation()) {
            // Modal offen lassen: PIN bzw. Passwörter müssen dem Empfänger mitgeteilt werden
            $this->result = $result->toArray();

            return;
        }

        $this->dispatch('nfmailer-hide');
        $this->resetExcept('page', 'mode', 'provider', 'aimodel');
    }

    /** Download-Portal verfügbar (nfgarching/share eingerichtet)? */
    #[Computed]
    public function portalAvailable(): bool
    {
        return app(MailDispatcher::class)->portalAvailable();
    }

    // ---------------------------------------------------------------- Entwürfe

    /** Knopf „Entwurf speichern“ ($force: trotz Änderung in einem anderen Fenster) */
    public function saveDraft(bool $force = false): void
    {
        $this->persistDraft($force, auto: false);
    }

    /** Automatisch (wire:poll, Schließen des Modals) – nur bei ungespeicherten Änderungen */
    public function autosave(): void
    {
        if (! config('nfmailer.drafts.autosave', true) || $this->result || $this->draftConflict
            || $this->currentHash() === $this->savedHash
            || (! $this->draftId && $this->isBlank())) {
            return;
        }

        $this->persistDraft(false, auto: true);
    }

    /** Nach Konflikt 'gone': Inhalt als neuen Entwurf sichern */
    public function saveAsNewDraft(): void
    {
        $this->draftId = null;
        $this->draftVersion = 0;
        $this->persistDraft(false, auto: false);
    }

    /** Nach Konflikt 'changed': Fassung aus dem anderen Fenster laden (eigene Änderungen verwerfen) */
    public function reloadDraft(): void
    {
        if ($this->draftId) {
            $this->loadDraft($this->draftId);
        }
    }

    /** Entwurf löschen und Modal schließen */
    public function discardDraft(): void
    {
        abort_unless(auth()->check(), 403);

        if ($this->draftId && $draft = SentMail::find($this->draftId)) {
            Gate::authorize('delete', $draft);
            app(DraftStore::class)->delete($draft);
            $this->dispatch('nfmailer-drafts-changed');
        }

        $this->dispatch('nfmailer-hide');
        $this->resetExcept('page', 'mode', 'provider', 'aimodel');
    }

    /** Ungespeicherte Änderungen (für Anzeige und Rückfrage beim Schließen) */
    #[Computed]
    public function dirty(): bool
    {
        return $this->currentHash() !== $this->savedHash;
    }

    protected function persistDraft(bool $force, bool $auto): void
    {
        if (! auth()->check() || ! config('nfmailer.drafts.enabled', true)) {
            return;
        }

        if ($this->draftId && $existing = SentMail::find($this->draftId)) {
            Gate::authorize('update', $existing);
        }

        $this->storeUploads();
        $store = app(DraftStore::class);

        try {
            $log = $store->save($this->currentDraft(), $this->draftId, $force ? null : $this->draftVersion, auth()->id());
        } catch (DraftConflict $e) {
            $this->handleConflict($e);

            return;
        }

        $store->discardUploads($this->discardedUploads);
        $this->discardedUploads = [];

        $this->draftId = $log->id;
        $this->draftVersion = $log->version;
        $this->draftConflict = null;
        $this->savedHash = $this->currentHash();
        $this->savedAt = now()->timezone(config('app.timezone'))->format('H:i');

        if (! $auto) {
            $this->notice = null;
        }

        $this->dispatch('nfmailer-drafts-changed');
    }

    /**
     * Entwurf laden ($copy = false) oder einen Entwurf bzw. eine versandte Mail als
     * neuen Entwurf kopieren ($copy = true).
     */
    protected function loadDraft(int $id, bool $copy = false): void
    {
        $log = SentMail::find($id);

        if (! $log) {
            $this->notice = 'Der Entwurf ist nicht mehr vorhanden.';

            return;
        }

        if ($copy) {
            Gate::authorize('view', $log);
            $log = app(DraftStore::class)->duplicate($log, auth()->id());
            $this->dispatch('nfmailer-drafts-changed');
        } elseif (! $log->isDraft()) {
            $this->notice = 'Diese E-Mail wurde bereits versandt und kann nicht mehr bearbeitet werden.';

            return;
        } else {
            Gate::authorize('update', $log);
        }

        $this->resetExcept('page', 'mode', 'provider', 'aimodel');
        $this->resetValidation();
        $this->applyDraft(MailDraft::fromLog($log));

        $this->draftId = $log->id;
        $this->draftVersion = $log->version;
        $this->savedHash = $this->currentHash();
        $this->savedAt = $log->updated_at?->timezone(config('app.timezone'))->format('d.m. H:i');
    }

    protected function applyDraft(MailDraft $draft): void
    {
        $this->to = $draft->to;
        $this->cc = $draft->cc;
        $this->bcc = $draft->bcc;
        $this->subject = $draft->subject;
        $this->body = $draft->body;
        $this->context = $draft->context;

        $this->asLinks = (bool) $draft->option('links');
        $this->usePin = (bool) $draft->option('pin');
        $this->pinByMail = (bool) $draft->option('pin_by_mail');
        $this->encrypt = (bool) $draft->option('encrypt');
        $this->archive = (bool) $draft->option('archive');
        $this->useSignature = (bool) $draft->option('signature');
        $this->attachmentsLabel = (string) $draft->option('attachments_label');
        $this->showCopies = (bool) $draft->option('show_copies') || $this->cc || $this->bcc;

        // Inzwischen gelöschte Dateien nicht anbieten, sondern melden
        $missing = [];
        $this->attachments = [];
        foreach ($draft->attachments as $a) {
            if (Storage::disk($a['disk'])->exists($a['path'])) {
                $this->attachments[] = $a + ['active' => true];
            } elseif ($a['active'] ?? true) {
                $missing[] = $a['name'];
            }
        }

        if ($missing) {
            $this->notice = 'Nicht mehr vorhanden und daher entfernt: '.implode(', ', $missing);
        }
    }

    /** Inhalt des Modals als MailDraft */
    protected function currentDraft(): MailDraft
    {
        return new MailDraft(
            to: $this->to,
            cc: $this->cc,
            bcc: $this->bcc,
            subject: $this->subject,
            body: $this->body,
            context: $this->context,
            attachments: $this->attachments,
            options: [
                'links' => $this->asLinks,
                'pin' => $this->usePin,
                'pin_by_mail' => $this->pinByMail,
                'encrypt' => $this->encrypt,
                'archive' => $this->archive,
                'signature' => $this->useSignature,
                'attachments_label' => $this->attachmentsLabel,
                'show_copies' => $this->showCopies,
            ],
        );
    }

    /** Nichts eingegeben: keine Empfänger, kein Betreff, kein Text, keine eigenen Anhänge */
    protected function isBlank(): bool
    {
        return ! $this->to && ! $this->cc && ! $this->bcc && ! $this->uploads
            && trim($this->subject) === '' && trim($this->body) === ''
            && ! array_filter($this->attachments, fn ($a) => ($a['origin'] ?? null) !== 'preset');
    }

    protected function currentHash(): string
    {
        return $this->currentDraft()->hash().':'.count($this->uploads);
    }

    /**
     * Temporäre Livewire-Uploads dauerhaft ablegen (Livewire löscht sie nach 24 Stunden)
     * und als Anhang mit origin 'upload' übernehmen.
     */
    protected function storeUploads(): void
    {
        $disk = config('nfmailer.uploads.disk', 'local');

        foreach ($this->uploads as $upload) {
            $this->attachments[] = [
                'disk' => $disk,
                'path' => $upload->store(config('nfmailer.uploads.directory', 'nfmailer/uploads'), $disk),
                'name' => $upload->getClientOriginalName(),
                'size' => $upload->getSize(),
                'origin' => 'upload',
                'active' => true,
            ];
        }

        $this->uploads = [];
    }

    protected function handleConflict(DraftConflict $e): void
    {
        $this->draftConflict = $e->reason;
        $this->notice = $e->getMessage();
    }

    /**
     * Übergibt Empfänger, Betreff und Text per mailto: an das Standard-Mailprogramm.
     * Anhänge lassen sich so nicht übergeben; es wird nichts protokolliert.
     */
    public function mailto(): void
    {
        abort_unless(auth()->check(), 403);

        $this->notice = null;
        $this->addFree();

        if ($this->getErrorBag()->has('freeInput')) {
            return;
        }

        $this->validate(
            ['to' => 'required|array|min:1'],
            [
                'to.required' => 'Bitte mindestens einen Empfänger unter „An“ angeben.',
                'to.min' => 'Bitte mindestens einen Empfänger unter „An“ angeben.',
            ],
        );

        $this->dispatch('nfmailer-mailto', url: $this->mailtoUrl());

        if ($this->activeAttachments() || $this->uploads) {
            // Modal offen lassen, damit die Anhänge nicht verloren gehen
            $this->notice = 'Anhänge werden an das Mailprogramm nicht übergeben – bitte dort von Hand anfügen.';

            return;
        }

        $this->dispatch('nfmailer-hide');
        $this->resetExcept('page', 'mode', 'provider', 'aimodel');
    }

    /** Anzuhängende Signatur – null, wenn keine konfiguriert oder der Schalter aus ist */
    protected function signature(): ?string
    {
        return $this->useSignature ? (config('nfmailer.signature') ?: null) : null;
    }

    /** mailto:-URL nach RFC 6068 (Zeilenumbrüche als CRLF) */
    protected function mailtoUrl(): string
    {
        $list = fn (array $recipients) => implode(',', array_map(
            fn ($r) => str_replace('%40', '@', rawurlencode($r['email'])),
            $recipients,
        ));

        $body = $this->body;
        if ($signature = $this->signature()) {
            $body .= "\n\n--\n".$signature;
        }

        $params = array_filter([
            'cc' => $list($this->cc),
            'bcc' => $list($this->bcc),
            'subject' => rawurlencode($this->subject),
            'body' => rawurlencode(preg_replace('/\r\n|\r|\n/', "\r\n", $body)),
        ]);

        $query = implode('&', array_map(fn ($k, $v) => $k.'='.$v, array_keys($params), $params));

        return 'mailto:'.$list($this->to).($query ? '?'.$query : '');
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
