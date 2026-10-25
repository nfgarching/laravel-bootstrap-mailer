<?php

namespace Nfgarching\Mailer\Sources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nfgarching\Mailer\Contracts\RecipientSource;
use Nfgarching\Mailer\Data\Recipient;

/**
 * Generische Quelle für ein beliebiges Eloquent-Model (Adressbuch, Mandanten, Gerichte …).
 * Wird vollständig über config/nfmailer.php konfiguriert.
 */
class ModelRecipientSource implements RecipientSource
{
    public function __construct(protected array $config = []) {}

    public function label(): string
    {
        return $this->config['label'] ?? 'Adressbuch';
    }

    public function suggestions(array $context): Collection
    {
        return collect();
    }

    public function search(string $term, array $context, int $limit): Collection
    {
        $class = $this->config['model'] ?? null;

        if (! $class || ! class_exists($class)) {
            return collect();
        }

        $columns = (array) ($this->config['search'] ?? ['email']);
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';

        /** @var Builder $query */
        $query = $class::query()->where(function (Builder $q) use ($columns, $like) {
            foreach ($columns as $col) {
                $q->orWhere($col, 'like', $like);
            }
        });

        return $query->limit($limit)->get()->flatMap(fn (Model $m) => $this->toRecipients($m));
    }

    /** @return Collection<int, Recipient> */
    protected function toRecipients(Model $m): Collection
    {
        $name = collect((array) ($this->config['name'] ?? []))
            ->map(fn ($col) => $m->getAttribute($col))
            ->filter()
            ->implode(' ');

        $hint = isset($this->config['hint']) ? $m->getAttribute($this->config['hint']) : null;

        // 'email' darf eine Spalte oder mehrere sein (z. B. email, email_privat)
        return collect((array) ($this->config['email'] ?? 'email'))
            ->map(fn ($col) => $m->getAttribute($col))
            ->filter()
            ->map(fn ($email) => Recipient::make($email, $name, $hint, $this->label()));
    }
}
