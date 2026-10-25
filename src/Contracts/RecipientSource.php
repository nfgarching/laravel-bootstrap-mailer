<?php

namespace Nfgarching\Mailer\Contracts;

use Illuminate\Support\Collection;
use Nfgarching\Mailer\Data\Recipient;

/**
 * Eine Adressquelle für das Empfänger-Modal.
 *
 * $context ist das Array, das beim Öffnen des Modals übergeben wurde,
 * z. B. ['akte' => 123]. Jede Quelle entscheidet selbst, ob sie damit etwas anfangen kann.
 */
interface RecipientSource
{
    /** Überschrift der Gruppe im Modal, z. B. "Beteiligte der Akte". */
    public function label(): string;

    /**
     * Vorschläge, die ohne Suche sofort angezeigt werden (z. B. alle Beteiligten der Akte).
     *
     * @return Collection<int, Recipient>
     */
    public function suggestions(array $context): Collection;

    /**
     * Treffer für einen Suchbegriff.
     *
     * @return Collection<int, Recipient>
     */
    public function search(string $term, array $context, int $limit): Collection;
}
