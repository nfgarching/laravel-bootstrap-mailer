<?php

namespace Nfgarching\Mailer\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Nfgarching\Mailer\Models\MailFavorite;

/**
 * Pflege der Favoriten des Empfänger-Modals (Seite nfmailer.favorites).
 *
 * Persönliche Favoriten (user_id = eigener User) pflegt jeder selbst,
 * gemeinsame (user_id null) nur User mit einer Rolle aus
 * nfmailer.favorites.manage_roles.
 */
class FavoritesManager extends Component
{
    public ?int $editingId = null;

    public string $email = '';

    public string $name = '';

    public string $hint = '';

    public int $sort = 0;

    /** true = gemeinsamer Favorit (für alle) */
    public bool $shared = false;

    #[Computed]
    public function canManageShared(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if (! method_exists($user, 'hasAnyRole')) {
            return true;
        }

        return $user->hasAnyRole((array) config('nfmailer.favorites.manage_roles', []));
    }

    /** @return Collection<int, MailFavorite> */
    #[Computed]
    public function personal(): Collection
    {
        return MailFavorite::query()->where('user_id', auth()->id())->orderBy('sort')->orderBy('name')->get();
    }

    /** @return Collection<int, MailFavorite> */
    #[Computed]
    public function sharedFavorites(): Collection
    {
        return MailFavorite::query()->whereNull('user_id')->orderBy('sort')->orderBy('name')->get();
    }

    public function save(): void
    {
        $this->shared = $this->shared && $this->canManageShared;
        $userId = $this->shared ? null : auth()->id();

        if ($this->editingId) {
            $this->authorizeFavorite($favorite = MailFavorite::findOrFail($this->editingId));
        }

        $this->email = mb_strtolower(trim($this->email));

        $this->validate([
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('mail_favorites', 'email')
                    ->where(fn ($q) => $userId ? $q->where('user_id', $userId) : $q->whereNull('user_id'))
                    ->ignore($this->editingId),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'hint' => ['nullable', 'string', 'max:255'],
            'sort' => ['integer', 'min:0', 'max:9999'],
        ], [
            'email.unique' => 'Diese Adresse ist dort schon als Favorit eingetragen.',
        ]);

        $data = [
            'user_id' => $userId,
            'email' => $this->email,
            'name' => trim($this->name) ?: null,
            'hint' => trim($this->hint) ?: null,
            'sort' => $this->sort,
        ];

        isset($favorite) ? $favorite->update($data) : MailFavorite::create($data);

        $this->cancel();
    }

    public function edit(int $id): void
    {
        $favorite = MailFavorite::findOrFail($id);
        $this->authorizeFavorite($favorite);

        $this->editingId = $favorite->id;
        $this->email = $favorite->email;
        $this->name = (string) $favorite->name;
        $this->hint = (string) $favorite->hint;
        $this->sort = $favorite->sort;
        $this->shared = ! $favorite->isPersonal();
        $this->resetValidation();
    }

    public function toggleActive(int $id): void
    {
        $favorite = MailFavorite::findOrFail($id);
        $this->authorizeFavorite($favorite);

        $favorite->update(['active' => ! $favorite->active]);
    }

    public function delete(int $id): void
    {
        $favorite = MailFavorite::findOrFail($id);
        $this->authorizeFavorite($favorite);

        $favorite->delete();

        if ($this->editingId === $id) {
            $this->cancel();
        }
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'email', 'name', 'hint', 'sort', 'shared');
        $this->resetValidation();
    }

    protected function authorizeFavorite(MailFavorite $favorite): void
    {
        $allowed = $favorite->isPersonal()
            ? $favorite->user_id === auth()->id()
            : $this->canManageShared;

        abort_unless($allowed, 403);
    }

    public function render()
    {
        return view('nfmailer::livewire.favorites');
    }
}
