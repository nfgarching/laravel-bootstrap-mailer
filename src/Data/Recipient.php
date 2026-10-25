<?php

namespace Nfgarching\Mailer\Data;

/**
 * Ein auswählbarer Empfänger (aus einer Quelle oder freier Eingabe).
 */
final readonly class Recipient
{
    public function __construct(
        public string $email,
        public ?string $name = null,
        public ?string $hint = null,     // z. B. "Gegner", "Mandant", Firma
        public ?string $source = null,   // Label der Quelle, z. B. "Beteiligte der Akte"
    ) {}

    public static function make(string $email, ?string $name = null, ?string $hint = null, ?string $source = null): self
    {
        $name = $name !== null ? trim(preg_replace('/\s+/u', ' ', $name)) : null;

        return new self(mb_strtolower(trim($email)), $name ?: null, $hint ?: null, $source);
    }

    public function key(): string
    {
        return mb_strtolower($this->email);
    }

    public function toArray(): array
    {
        return [
            'email'  => $this->email,
            'name'   => $this->name,
            'hint'   => $this->hint,
            'source' => $this->source,
        ];
    }
}
