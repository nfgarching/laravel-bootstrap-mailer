<?php

namespace Nfgarching\Mailer\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Prüft Anhänge, die beim Öffnen des Modals übergeben werden.
 * Da das Öffnen vom Browser ausgelöst wird, darf nur auf freigegebene
 * Disks/Verzeichnisse zugegriffen werden.
 */
class AttachmentGuard
{
    /**
     * @param array{disk?: string, path: string, name?: string} $a
     * @return array{disk: string, path: string, name: string, size: int, origin: string}|null
     */
    public static function check(array $a): ?array
    {
        $disk = $a['disk'] ?? 'local';
        $path = ltrim(str_replace('\\', '/', (string) ($a['path'] ?? '')), '/');

        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        if (! in_array($disk, (array) config('nfmailer.attachments.allowed_disks', []), true)) {
            return null;
        }

        // Liste = für alle Disks; [disk => Liste] = je Disk (fehlende Disk = ohne Einschränkung)
        $prefixes = (array) config('nfmailer.attachments.allowed_prefixes', []);
        if (! array_is_list($prefixes)) {
            $prefixes = (array) ($prefixes[$disk] ?? []);
        }
        if ($prefixes && ! Str::startsWith($path, $prefixes)) {
            return null;
        }

        $storage = Storage::disk($disk);
        if (! $storage->exists($path)) {
            return null;
        }

        return [
            'disk'   => $disk,
            'path'   => $path,
            'name'   => $a['name'] ?? basename($path),
            'size'   => (int) $storage->size($path),
            'origin' => 'preset',
        ];
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.') . ' MB'
            : max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
