<?php

namespace Nfgarching\Mailer\Services;

use Nfgarching\Mailer\Models\SentMail;

/**
 * Ergebnis von MailDispatcher::dispatch() – für die Anzeige im Modal bzw. die
 * Benachrichtigung beim zeitversetzten Versand.
 */
final class DispatchResult
{
    /**
     * @param  list<array{email: string, name: ?string, password: string, mailed: bool}>  $passwords
     * @param  list<string>  $errors  Teilfehler beim Portal-Versand (je Empfänger)
     */
    public function __construct(
        public readonly SentMail $log,
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly ?string $pin = null,
        public readonly bool $pinMailed = false,
        public readonly bool $portal = false,
        public readonly array $passwords = [],
        public readonly array $errors = [],
        public readonly bool $signed = false,
        public readonly bool $encrypted = false,
    ) {}

    public static function failed(SentMail $log, string $error): self
    {
        return new self($log, false, $error);
    }

    /** Ergebnisanzeige nötig: PIN bzw. Passwörter müssen dem Absender gezeigt werden */
    public function needsConfirmation(): bool
    {
        return $this->pin !== null || $this->passwords !== [] || $this->errors !== [];
    }

    /** Für $result im Modal (Partial mail-composer.result) */
    public function toArray(): array
    {
        return [
            'id' => $this->log->id,
            'pin' => $this->pin,
            'pinMailed' => $this->pinMailed,
            'portal' => $this->portal,
            'passwords' => $this->passwords,
            'signed' => $this->signed,
            'encrypted' => $this->encrypted,
        ];
    }
}
