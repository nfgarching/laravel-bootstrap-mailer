<?php

namespace Nfgarching\Mailer\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Nfgarching\Mailer\Data\MailDraft;
use Nfgarching\Mailer\Exceptions\DraftConflict;
use Nfgarching\Mailer\Models\SentMail;

/**
 * Speichern, Übernehmen zum Versand, Duplizieren und Löschen von Entwürfen.
 *
 * Gleichzeitiges Bearbeiten: Jeder Entwurf trägt einen Zähler 'version'. Wer mit
 * einer veralteten Version speichert oder sendet, erhält DraftConflict statt die
 * Änderungen des anderen Fensters still zu überschreiben.
 */
class DraftStore
{
    /**
     * Entwurf anlegen ($id null) oder aktualisieren.
     *
     * @param  ?int  $expectedVersion  null = ohne Versionsprüfung („trotzdem speichern“)
     */
    public function save(MailDraft $draft, ?int $id, ?int $expectedVersion, mixed $userId): SentMail
    {
        if ($id === null) {
            return SentMail::create($draft->toAttributes() + [
                'user_id' => $userId,
                'status' => SentMail::DRAFT,
                'version' => 1,
            ]);
        }

        return DB::transaction(function () use ($draft, $id, $expectedVersion) {
            $log = $this->lockDraft($id, $expectedVersion);
            $log->fill($draft->toAttributes());
            $log->version = $log->version + 1;
            $log->save();

            return $log;
        });
    }

    /**
     * Entwurf zum Versand übernehmen: Status 'pending', danach MailDispatcher::dispatch().
     * Ohne $id (nie gespeichert) wird der Protokolleintrag direkt angelegt.
     */
    public function claimForSending(MailDraft $draft, ?int $id, ?int $expectedVersion, mixed $userId): SentMail
    {
        if ($id === null) {
            return SentMail::create($draft->toAttributes() + [
                'user_id' => $userId,
                'status' => SentMail::PENDING,
            ]);
        }

        return DB::transaction(function () use ($draft, $id, $expectedVersion) {
            $log = $this->lockDraft($id, $expectedVersion);
            $log->fill($draft->toAttributes());
            $log->status = SentMail::PENDING;
            $log->version = $log->version + 1;
            $log->save();

            return $log;
        });
    }

    /** Neuer Entwurf mit dem Inhalt eines Entwurfs oder einer versandten Mail */
    public function duplicate(SentMail $source, mixed $userId): SentMail
    {
        $draft = MailDraft::fromLog($source);

        // Hochgeladene Dateien kopieren: jeder Entwurf löscht beim Verwerfen nur seine eigenen
        $uploads = array_column($source->uploadFiles(), 'path');
        foreach ($draft->attachments as $i => $a) {
            if (in_array($a['path'] ?? null, $uploads, true)) {
                $draft->attachments[$i]['path'] = $this->copyUpload($a['disk'], $a['path']);
            }
        }

        // Versandte Mail: nur die mitgesendeten Anhänge, alle wieder aktiv
        if (! $source->isDraft()) {
            $draft->attachments = array_map(fn ($a) => $a + ['active' => true], $draft->attachments);
        }

        return SentMail::create($draft->toAttributes() + [
            'user_id' => $userId,
            'status' => SentMail::DRAFT,
            'version' => 1,
        ]);
    }

    /** Entwurf mit seinen hochgeladenen Dateien löschen */
    public function delete(SentMail $draft): void
    {
        abort_unless($draft->isDraft(), 409, 'Nur Entwürfe können gelöscht werden.');

        $draft->deleteUploadFiles();
        $draft->delete();
    }

    /** Hochgeladene Dateien löschen, die aus einem Entwurf entfernt wurden */
    public function discardUploads(array $files): void
    {
        $dir = trim((string) config('nfmailer.uploads.directory', 'nfmailer/uploads'), '/').'/';

        foreach ($files as $f) {
            if (Str::startsWith((string) ($f['path'] ?? ''), $dir)) {
                Storage::disk($f['disk'])->delete($f['path']);
            }
        }
    }

    /** Entwürfe, die länger als $days Tage nicht geändert wurden, samt Uploads löschen */
    public function prune(int $days, bool $dryRun = false): int
    {
        $count = 0;

        SentMail::drafts()
            ->where('updated_at', '<', now()->subDays($days))
            ->orderBy('id')
            ->each(function (SentMail $draft) use ($dryRun, &$count) {
                $dryRun ?: $this->delete($draft);
                $count++;
            });

        return $count;
    }

    /** Pfade aller Uploads, die noch von einem Entwurf verwendet werden: [path => true] */
    public function uploadsInUse(): array
    {
        $paths = [];

        SentMail::drafts()->orderBy('id')->each(function (SentMail $draft) use (&$paths) {
            foreach ($draft->uploadFiles() as $f) {
                $paths[$f['path']] = true;
            }
        });

        return $paths;
    }

    protected function lockDraft(int $id, ?int $expectedVersion): SentMail
    {
        $log = SentMail::whereKey($id)->lockForUpdate()->first();

        if (! $log || ! $log->isDraft()) {
            throw new DraftConflict(DraftConflict::GONE);
        }

        if ($expectedVersion !== null && $log->version !== $expectedVersion) {
            throw new DraftConflict(DraftConflict::CHANGED);
        }

        return $log;
    }

    protected function copyUpload(string $disk, string $path): string
    {
        $dir = trim((string) config('nfmailer.uploads.directory', 'nfmailer/uploads'), '/');
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $target = $dir.'/'.Str::random(40).($ext !== '' ? '.'.$ext : '');

        Storage::disk($disk)->copy($path, $target);

        return $target;
    }
}
