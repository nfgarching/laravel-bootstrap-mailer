<?php

namespace Nfgarching\Mailer\Sources;

use Nfgarching\Mailer\Contracts\AttachmentSource;
use Nfgarching\Mailer\Support\AttachmentGuard;

/**
 * Fragt alle konfigurierten Anhang-Quellen ab. Die Schlüssel der Einträge
 * ("{Quelle}|{ID}") wählt der Browser aus; resolve() löst sie wieder auf.
 */
class AttachmentResolver
{
    /** @var list<AttachmentSource>|null */
    protected ?array $sources = null;

    /** @return list<AttachmentSource> */
    public function sources(): array
    {
        return $this->sources ??= collect(config('nfmailer.attachment_sources', []))
            ->map(function ($entry) {
                $entry = is_string($entry) ? ['class' => $entry] : $entry;

                return app()->make($entry['class'], ['config' => $entry]);
            })
            ->filter(fn ($s) => $s instanceof AttachmentSource)
            ->values()
            ->all();
    }

    /**
     * Auswählbare Dateien, gruppiert nach Quelle.
     *
     * @return array<string, list<array>> [Label => [Eintrag mit 'key', …]]
     */
    public function items(array $context): array
    {
        $groups = [];

        foreach ($this->sources() as $i => $source) {
            $items = $source->items($context)
                ->map(fn (array $item) => ['key' => $i.'|'.$item['id'], ...$item])
                ->values()
                ->all();

            if ($items) {
                $groups[$source->label()] = $items;
            }
        }

        return $groups;
    }

    /**
     * Geprüfter Anhang zu einem Schlüssel aus items().
     *
     * @return array{disk: string, path: string, name: string, size: int, origin: string}|null
     */
    public function resolve(string $key, array $context): ?array
    {
        [$i, $id] = array_pad(explode('|', $key, 2), 2, '');
        $source = ctype_digit($i) ? ($this->sources()[(int) $i] ?? null) : null;

        $file = $source?->resolve($id, $context);
        $checked = $file ? AttachmentGuard::check($file) : null;

        return $checked ? [...$checked, 'origin' => 'source'] : null;
    }
}
