<div class="row g-4">
    {{-- ============================================ Formular --}}
    <div class="col-lg-4">
        <form class="card" wire:submit="save">
            <div class="card-header fw-semibold">
                {{ $editingId ? 'Favorit bearbeiten' : 'Favorit hinzufügen' }}
            </div>
            <div class="card-body d-flex flex-column gap-2">
                <div>
                    <label class="form-label small mb-1" for="fav-email">E-Mail</label>
                    <input id="fav-email" type="email" class="form-control @error('email') is-invalid @enderror"
                        wire:model="email" autocomplete="off" required>
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div>
                    <label class="form-label small mb-1" for="fav-name">Name</label>
                    <input id="fav-name" type="text" class="form-control" wire:model="name"
                        placeholder="z. B. Buchhaltung">
                </div>
                <div>
                    <label class="form-label small mb-1" for="fav-hint">Zusatz</label>
                    <input id="fav-hint" type="text" class="form-control" wire:model="hint"
                        placeholder="z. B. Funktionsadresse">
                </div>
                <div>
                    <label class="form-label small mb-1" for="fav-sort">Reihenfolge</label>
                    <input id="fav-sort" type="number" min="0" class="form-control" wire:model="sort">
                </div>
                @if ($this->canManageShared)
                    <div class="form-check">
                        <input id="fav-shared" type="checkbox" class="form-check-input" wire:model="shared">
                        <label class="form-check-label" for="fav-shared">Für alle (gemeinsamer Favorit)</label>
                    </div>
                @endif
            </div>
            <div class="card-footer d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg"></i> Speichern
                </button>
                @if ($editingId)
                    <button type="button" class="btn btn-link btn-sm" wire:click="cancel">Abbrechen</button>
                @endif
            </div>
        </form>
    </div>

    {{-- ============================================ Listen --}}
    <div class="col-lg-8 d-flex flex-column gap-4">
        @foreach ([
            ['title' => 'Meine Favoriten', 'items' => $this->personal, 'editable' => true, 'empty' => 'Noch keine persönlichen Favoriten – im E-Mail-Fenster mit dem Stern oder hier hinzufügen.'],
            ['title' => 'Gemeinsame Favoriten (für alle)', 'items' => $this->sharedFavorites, 'editable' => $this->canManageShared, 'empty' => 'Keine gemeinsamen Favoriten.'],
        ] as $list)
            <div class="card" wire:key="list-{{ $loop->index }}">
                <div class="card-header fw-semibold">{{ $list['title'] }}</div>
                <div class="list-group list-group-flush">
                    @forelse ($list['items'] as $fav)
                        <div class="list-group-item d-flex align-items-center gap-2 {{ $fav->active ? '' : 'opacity-50' }}"
                            wire:key="fav-{{ $fav->id }}">
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-medium text-truncate">{{ $fav->name ?: $fav->email }}</div>
                                <div class="small text-body-secondary text-truncate">
                                    {{ $fav->email }}@if ($fav->hint)
                                        · {{ $fav->hint }}
                                    @endif
                                    @unless ($fav->active)
                                        · <em>ausgeblendet</em>
                                    @endunless
                                </div>
                            </div>
                            @if ($list['editable'])
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary" title="Bearbeiten"
                                        wire:click="edit({{ $fav->id }})"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-outline-secondary"
                                        title="{{ $fav->active ? 'Ausblenden' : 'Einblenden' }}"
                                        wire:click="toggleActive({{ $fav->id }})">
                                        <i class="bi {{ $fav->active ? 'bi-eye-slash' : 'bi-eye' }}"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger" title="Löschen"
                                        wire:click="delete({{ $fav->id }})"
                                        wire:confirm="Favorit {{ $fav->email }} löschen?"><i class="bi bi-trash"></i></button>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item small text-body-secondary">{{ $list['empty'] }}</div>
                    @endforelse
                </div>
            </div>
        @endforeach

        <p class="small text-body-secondary mb-0">
            Bearbeiter erscheinen im E-Mail-Fenster automatisch (Logins mit zugeordnetem Bearbeiter)
            und müssen hier nicht eingetragen werden.
        </p>
    </div>
</div>
