<?php

namespace Nfgarching\Mailer\Livewire;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Nfgarching\Mailer\Models\SentMail;
use Nfgarching\Mailer\Services\DraftStore;

/**
 * Liste der Entwürfe (Seite nfmailer.drafts): öffnen, duplizieren, löschen.
 *
 * Mit 'context' (z. B. ['akte' => 123]) nur die Entwürfe dieser Akte – so lässt sich
 * die Liste auch in die Aktenansicht der Anwendung einbinden:
 *   <livewire:nf-mail-drafts :context="['akte' => $akte->id]" />
 *
 * Betreff und Empfänger sind verschlüsselt gespeichert: Suche und Sortierung
 * erfolgen nach dem Entschlüsseln in PHP.
 */
class DraftsManager extends Component
{
    public array $context = [];

    public string $search = '';

    /** Kompakte Darstellung (in einer Aktenansicht): ohne Suche und Spalte „Akte“ */
    public bool $compact = false;

    #[On('nfmailer-drafts-changed')]
    public function refresh(): void
    {
        unset($this->drafts);
    }

    /** @return list<array{id: int, updated: string, to: string, subject: string, context: ?string, attachments: int, mine: bool}> */
    #[Computed]
    public function drafts(): array
    {
        $query = SentMail::drafts()->visibleTo(auth()->id())->latest('updated_at');

        if ($this->context) {
            $query->forContext($this->context);
        }

        $term = mb_strtolower(trim($this->search));

        return $query->limit(500)->get()
            ->map(fn (SentMail $d) => [
                'id' => $d->id,
                'updated' => $d->updated_at?->timezone(config('app.timezone'))->format('d.m.Y H:i') ?? '',
                'to' => collect([...(array) $d->to, ...(array) $d->cc])
                    ->map(fn ($r) => $r['name'] ?: $r['email'])->implode(', '),
                'subject' => (string) $d->subject,
                'context' => $this->contextLabel((array) $d->context),
                'attachments' => count(array_filter((array) $d->attachments, fn ($a) => $a['active'] ?? true)),
                'mine' => (string) $d->user_id === (string) auth()->id(),
            ])
            ->filter(fn ($row) => $term === '' || str_contains(mb_strtolower($row['to'].' '.$row['subject'].' '.$row['context']), $term))
            ->values()
            ->all();
    }

    public function duplicate(int $id): void
    {
        $draft = SentMail::findOrFail($id);
        Gate::authorize('view', $draft);

        $copy = app(DraftStore::class)->duplicate($draft, auth()->id());
        unset($this->drafts);

        $this->dispatch('nfmailer-open', options: ['draft' => $copy->id]);
    }

    public function delete(int $id): void
    {
        $draft = SentMail::find($id);

        if ($draft) {
            Gate::authorize('delete', $draft);
            app(DraftStore::class)->delete($draft);
        }

        unset($this->drafts);
    }

    protected function contextLabel(array $context): ?string
    {
        $parts = collect($context)
            ->filter(fn ($value) => is_scalar($value) && $value !== '')
            ->map(fn ($value, $key) => Str::headline((string) $key).' #'.$value);

        return $parts->isEmpty() ? null : $parts->implode(' · ');
    }

    public function render()
    {
        return view('nfmailer::livewire.drafts');
    }
}
