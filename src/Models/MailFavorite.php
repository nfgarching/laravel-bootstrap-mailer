<?php

namespace Nfgarching\Mailer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nfgarching\Mailer\Data\Recipient;

/**
 * Favorit des Empfänger-Modals.
 *
 * user_id null = gemeinsamer Favorit (für alle), sonst persönlicher Favorit.
 */
class MailFavorite extends Model
{
    protected $table = 'mail_favorites';

    protected $fillable = ['user_id', 'email', 'name', 'hint', 'sort', 'active'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'sort' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** Adresse immer kleingeschrieben speichern (Vergleich mit Recipient::key()). */
    protected function setEmailAttribute(?string $value): void
    {
        $this->attributes['email'] = mb_strtolower(trim((string) $value));
    }

    /** Aktive gemeinsame Favoriten plus die persönlichen des Users; persönliche zuerst. */
    public function scopeVisibleFor(Builder $query, ?int $userId): Builder
    {
        return $query
            ->where('active', true)
            ->where(fn (Builder $q) => $q->whereNull('user_id')
                ->when($userId, fn (Builder $q) => $q->orWhere('user_id', $userId)))
            ->orderByRaw('user_id is null')
            ->orderBy('sort')
            ->orderBy('name');
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    public function toRecipient(?string $source = null): Recipient
    {
        return Recipient::make($this->email, $this->name, $this->hint, $source);
    }
}
