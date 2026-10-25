<?php

namespace Nfgarching\Mailer\Sources;

use Illuminate\Support\Collection;
use Nfgarching\Mailer\Contracts\RecipientSource;
use Nfgarching\Mailer\Data\Recipient;

/**
 * Fragt alle konfigurierten Quellen ab und führt die Ergebnisse zusammen.
 */
class RecipientResolver
{
    /** @var list<RecipientSource>|null */
    protected ?array $sources = null;

    /** @return list<RecipientSource> */
    public function sources(): array
    {
        return $this->sources ??= collect(config('nfmailer.sources', []))
            ->map(function ($entry) {
                $entry = is_string($entry) ? ['class' => $entry] : $entry;

                return app()->make($entry['class'], ['config' => $entry]);
            })
            ->filter(fn ($s) => $s instanceof RecipientSource)
            ->values()
            ->all();
    }

    /**
     * Vorschläge ohne Suche, gruppiert nach Quelle.
     *
     * @return array<string, list<array>>  [Label => [Recipient-Array, …]]
     */
    public function suggestions(array $context): array
    {
        $groups = [];

        foreach ($this->sources() as $source) {
            $items = $this->unique($source->suggestions($context));
            if ($items->isNotEmpty()) {
                $groups[$source->label()] = $items->map->toArray()->values()->all();
            }
        }

        return $groups;
    }

    /**
     * Suchtreffer aller Quellen, dedupliziert nach E-Mail (erste Quelle gewinnt).
     *
     * @return list<array>
     */
    public function search(string $term, array $context): array
    {
        $limit = (int) config('nfmailer.search.limit', 12);

        $all = collect($this->sources())
            ->flatMap(fn (RecipientSource $s) => $s->search($term, $context, $limit));

        return $this->unique($all)->take($limit * 2)->map->toArray()->values()->all();
    }

    /** @param Collection<int, Recipient> $items */
    protected function unique(Collection $items): Collection
    {
        return $items
            ->filter(fn (Recipient $r) => filter_var($r->email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE))
            ->unique(fn (Recipient $r) => $r->key());
    }
}
