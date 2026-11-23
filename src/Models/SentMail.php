<?php

namespace Nfgarching\Mailer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Versandprotokoll und Entwürfe: Ein Datensatz durchläuft
 *   draft → pending → (queued →) sent | failed
 * (später zusätzlich scheduled, sending, cancelled – siehe Planungsskizze).
 */
class SentMail extends Model
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $table = 'sent_mails';

    protected $guarded = [];

    /**
     * Inhalt und Empfänger liegen verschlüsselt in der Datenbank (APP_KEY).
     * Folge: keine SQL-Suche über Betreff/Text/Empfänger; Filtern über context_key.
     */
    protected $casts = [
        'subject' => 'encrypted',
        'body' => 'encrypted',
        'to' => 'encrypted:array',
        'cc' => 'encrypted:array',
        'bcc' => 'encrypted:array',
        'attachments' => 'encrypted:array',
        'pin' => 'encrypted',
        'context' => 'array',
        'as_links' => 'boolean',
        'signed' => 'boolean',
        'encrypted' => 'boolean',
        'archive' => 'boolean',
        'options' => 'array',
        'version' => 'integer',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    protected $hidden = ['pin'];

    public function links(): HasMany
    {
        return $this->hasMany(MailLink::class, 'sent_mail_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** SentMail::drafts()->forContext(['akte' => 123])->latest('updated_at')->get() */
    public function scopeDrafts(Builder $q): Builder
    {
        return $q->where('status', self::DRAFT);
    }

    /** Entwürfe, die ein User sehen darf: eigene, mit nfmailer.drafts.shared alle */
    public function scopeVisibleTo(Builder $q, mixed $userId): Builder
    {
        return config('nfmailer.drafts.shared', false) ? $q : $q->where('user_id', $userId);
    }

    /**
     * Im Modal hochgeladene Dateien dieses Datensatzes (origin 'upload', im Upload-Verzeichnis).
     * Übergebene Anhänge und Dokumente der Akte gehören der Anwendung und werden nie gelöscht.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function uploadFiles(): array
    {
        $dir = trim((string) config('nfmailer.uploads.directory', 'nfmailer/uploads'), '/').'/';
        $disk = (string) config('nfmailer.uploads.disk', 'local');

        return array_values(array_map(
            fn (array $a) => ['disk' => $a['disk'], 'path' => $a['path']],
            array_filter((array) ($this->attachments ?? []), fn (array $a) => ($a['origin'] ?? null) === 'upload'
                && ($a['disk'] ?? null) === $disk
                && Str::startsWith((string) ($a['path'] ?? ''), $dir)),
        ));
    }

    public function deleteUploadFiles(): void
    {
        foreach ($this->uploadFiles() as $f) {
            Storage::disk($f['disk'])->delete($f['path']);
        }
    }

    /** SentMail::forContext(['akte' => 123])->latest()->get() */
    public function scopeForContext(Builder $q, array $context): Builder
    {
        return $q->where('context_key', self::contextKey($context));
    }

    public static function contextKey(array $context): ?string
    {
        if (! $context) {
            return null;
        }

        $key = array_key_first($context);

        return is_scalar($context[$key]) ? $key.':'.$context[$key] : null;
    }
}
