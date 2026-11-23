<?php

namespace Nfgarching\Mailer\Exceptions;

use RuntimeException;

/**
 * Ein Entwurf ließ sich nicht speichern bzw. versenden, weil er inzwischen
 * geändert wurde (anderes Fenster), versendet oder gelöscht ist.
 */
class DraftConflict extends RuntimeException
{
    public const CHANGED = 'changed';

    public const GONE = 'gone';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason === self::CHANGED
            ? 'Der Entwurf wurde inzwischen in einem anderen Fenster geändert.'
            : 'Der Entwurf ist nicht mehr vorhanden (versendet oder gelöscht).');
    }
}
