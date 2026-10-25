<?php

namespace App\Mail\Recipients;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Nfgarching\Mailer\Contracts\RecipientSource;
use Nfgarching\Mailer\Data\Recipient;

/**
 * BEISPIEL – an die eigenen Models anpassen.
 *
 * Zeigt beim Öffnen des Modals sofort alle Beteiligten der Akte mit E-Mail-Adresse
 * (Mandant, Gegner, Gegenanwalt, Gericht …) und durchsucht sie bei Eingabe.
 *
 * Öffnen mit:  'context' => ['akte' => $akte->id]
 *
 * Angenommenes Schema (bitte anpassen):
 *   Akte  hasMany  Beteiligter (rolle, name, firma, email, email2) 
 */
class AkteBeteiligteSource implements RecipientSource
{
    public function __construct(protected array $config = []) {}

    public function label(): string
    {
        return $this->config['label'] ?? 'Beteiligte der Akte';
    }

    public function suggestions(array $context): Collection
    {
        $akte = $this->akte($context);

        if (! $akte) {
            return collect();
        }

        return $akte->beteiligte
            ->sortBy(fn ($b) => $this->rank($b->rolle))
            ->flatMap(fn ($b) => collect([$b->email, $b->email2 ?? null])
                ->filter()
                ->map(fn ($email) => Recipient::make(
                    $email,
                    $b->name,
                    collect([$b->rolle, $b->firma])->filter()->implode(' · '),
                    $this->label(),
                )));
    }

    public function search(string $term, array $context, int $limit): Collection
    {
        $term = mb_strtolower($term);

        return $this->suggestions($context)
            ->filter(fn (Recipient $r) => str_contains(mb_strtolower($r->email . ' ' . $r->name . ' ' . $r->hint), $term))
            ->take($limit);
    }

    protected function akte(array $context)
    {
        $id = $context['akte'] ?? null;

        if (! $id) {
            return null;
        }

        $akte = \App\Models\Akte::with('beteiligte')->find($id);

        // Nur Akten, die der angemeldete Nutzer sehen darf
        return $akte && Gate::allows('view', $akte) ? $akte : null;
    }

    /** Reihenfolge in der Liste */
    protected function rank(?string $rolle): int
    {
        return match (mb_strtolower((string) $rolle)) {
            'mandant', 'mandantin'   => 0,
            'gegenanwalt'            => 1,
            'gegner', 'gegnerin'     => 2,
            'gericht'                => 3,
            default                  => 9,
        };
    }
}
