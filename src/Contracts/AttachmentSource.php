<?php

namespace Nfgarching\Mailer\Contracts;

use Illuminate\Support\Collection;

/**
 * Eine Quelle für Anhänge, die im Modal ausgewählt werden können (z. B. Dokumente der Akte).
 *
 * $context ist das Array, das beim Öffnen des Modals übergeben wurde, z. B. ['akte' => 123].
 * Vom Browser kommt nur die ID eines Eintrags; den Pfad ermittelt resolve() auf dem Server,
 * danach prüft AttachmentGuard ihn wie jeden übergebenen Anhang.
 */
interface AttachmentSource
{
    /** Überschrift der Gruppe im Modal, z. B. "Dokumente der Akte". */
    public function label(): string;

    /**
     * Auswählbare Dateien; 'group' fasst Dateien in der Liste zusammen (z. B. das Dokument).
     *
     * @return Collection<int, array{id: string, name: string, group: ?string, size: ?int, disk: string, path: string}>
     */
    public function items(array $context): Collection;

    /**
     * Datei zu einer ID aus items(), null wenn sie im Kontext nicht (mehr) verfügbar ist.
     *
     * @return array{disk: string, path: string, name: string}|null
     */
    public function resolve(string $id, array $context): ?array;
}
