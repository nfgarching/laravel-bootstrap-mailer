<?php

namespace Nfgarching\Mailer\Sources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Nfgarching\Mailer\Contracts\RecipientSource;
use Nfgarching\Mailer\Models\MailFavorite;

/**
 * Favoriten (Tabelle mail_favorites): gemeinsame Adressen wie Buchhaltung
 * plus die persönlichen Favoriten des angemeldeten Users.
 * Erscheinen ohne Suche als Vorschläge und werden auch durchsucht.
 */
class FavoriteRecipientSource implements RecipientSource
{
    public function __construct(protected array $config = []) {}

    public function label(): string
    {
        return $this->config['label'] ?? 'Favoriten';
    }

    public function suggestions(array $context): Collection
    {
        return MailFavorite::query()
            ->visibleFor(auth()->id())
            ->get()
            ->map(fn (MailFavorite $f) => $f->toRecipient($this->label()));
    }

    public function search(string $term, array $context, int $limit): Collection
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return MailFavorite::query()
            ->visibleFor(auth()->id())
            ->where(fn (Builder $q) => $q
                ->where('email', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('hint', 'like', $like))
            ->limit($limit)
            ->get()
            ->map(fn (MailFavorite $f) => $f->toRecipient($this->label()));
    }
}
