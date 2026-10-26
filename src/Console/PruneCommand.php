<?php

namespace Nfgarching\Mailer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Nfgarching\Mailer\Models\MailLink;

/**
 * Datensparsamkeit: entfernt alte Download-Links und (optional) alte Uploads.
 *
 * Scheduler (routes/console.php):  Schedule::command('nfmailer:prune')->daily();
 */
class PruneCommand extends Command
{
    protected $signature = 'nfmailer:prune {--dry-run : Nur anzeigen, nichts löschen}';

    protected $description = 'Abgelaufene Download-Links und alte Uploads entfernen';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Links
        $days = config('nfmailer.prune.links_after_days');
        if ($days !== null) {
            $cutoff = now()->subDays((int) $days);
            $query = MailLink::query()->where(fn ($q) => $q
                ->where('expires_at', '<', $cutoff)
                ->orWhere('revoked_at', '<', $cutoff));

            $count = $query->count();
            $dry ?: $query->delete();
            $this->line(($dry ? 'Würde ' : '') . "{$count} Download-Link(s) entfernen" . ($dry ? '.' : ' – entfernt.'));
        }

        // Uploads
        $days = config('nfmailer.prune.uploads_after_days');
        if ($days !== null) {
            $disk = Storage::disk(config('nfmailer.uploads.disk', 'local'));
            $cutoff = now()->subDays((int) $days)->getTimestamp();

            // Dateien, auf die noch ein gültiger Link zeigt, bleiben erhalten
            $active = MailLink::query()
                ->whereNull('revoked_at')
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('path')
                ->flip();

            $deleted = 0;
            foreach ($disk->allFiles(config('nfmailer.uploads.directory', 'nfmailer/uploads')) as $file) {
                if (! isset($active[$file]) && $disk->lastModified($file) < $cutoff) {
                    $dry ?: $disk->delete($file);
                    $deleted++;
                }
            }

            $this->line(($dry ? 'Würde ' : '') . "{$deleted} Upload(s) löschen" . ($dry ? '.' : ' – gelöscht.'));
        }

        return self::SUCCESS;
    }
}
