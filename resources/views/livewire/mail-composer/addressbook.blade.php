{{-- Offcanvas „Adressbuch“: Suche, Vorschläge, Favoriten, Mehrfachauswahl.
     Ohne Backdrop, damit das Formular daneben bedienbar bleibt und die Chips sichtbar wachsen.
     wire:ignore.self: Livewire darf die von Bootstrap gesetzte show-Klasse nicht entfernen. --}}
@php
    $searching = mb_strlen(trim($search)) >= (int) config('nfmailer.search.min_chars', 2);
    $groups = $searching ? ['Suchergebnisse' => $this->results] : $this->suggestions;
    $assigned = $this->assigned;
    $favorites = $this->favoriteKeys;
@endphp

<div class="offcanvas offcanvas-end nfm-offcanvas" tabindex="-1" id="nfmAddressbook"
    aria-labelledby="nfmAddressbookTitle" data-bs-backdrop="false" data-bs-scroll="true" wire:ignore.self
    x-on:keydown.escape.stop x-on:shown-bs-offcanvas.dot="$el.querySelector('input[type=search]')?.focus()">
    <div class="offcanvas-header border-bottom flex-column align-items-stretch gap-2">
        <div class="d-flex align-items-center">
            <h5 class="offcanvas-title" id="nfmAddressbookTitle"><i class="bi bi-person-lines-fill me-1"></i> Adressbuch</h5>
            <a href="{{ route('nfmailer.favorites') }}" target="_blank" class="ms-auto me-3 small text-decoration-none"
                title="Favoriten verwalten"><i class="bi bi-star"></i> Favoriten</a>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Schließen"></button>
        </div>
        <input type="search" class="form-control" placeholder="Name, Firma oder E-Mail suchen …"
            wire:model.live.debounce.300ms="search" autocomplete="off">
        <div class="small text-body-secondary">
            Klick übernimmt nach <strong class="text-primary">{{ $fields[$activeField] }}</strong> – oder mehrere markieren.
        </div>
    </div>

    <div class="offcanvas-body p-0">
        <div class="list-group list-group-flush" wire:loading.class="opacity-50" wire:target="search">
            @forelse ($groups as $groupLabel => $items)
                <div class="list-group-item bg-body-tertiary d-flex align-items-center py-1 small fw-semibold sticky-top"
                    wire:key="grp-{{ $groupLabel }}">
                    <span>{{ $groupLabel }}</span>
                    @if (count($items) > 1)
                        <button type="button" class="btn btn-link btn-sm p-0 ms-auto text-decoration-none"
                            x-on:click="$wire.picked = [...new Set([...$wire.picked, ...@js(array_column($items, 'email'))])]">
                            alle markieren
                        </button>
                    @endif
                </div>

                @forelse ($items as $r)
                    @php
                        $in = $assigned[mb_strtolower($r['email'])] ?? null;
                        $fav = isset($favorites[mb_strtolower($r['email'])]);
                    @endphp
                    <div class="list-group-item d-flex align-items-center gap-2 py-2 {{ $in ? 'nfm-taken' : '' }}"
                        wire:key="opt-{{ $groupLabel }}-{{ $r['email'] }}">
                        <input class="form-check-input flex-shrink-0 m-0" type="checkbox" value="{{ $r['email'] }}"
                            wire:model="picked" aria-label="markieren">
                        <button type="button"
                            class="btn btn-link p-0 text-start text-body text-decoration-none flex-grow-1 min-w-0"
                            wire:click="add(@js($r['email']))">
                            <div class="fw-medium text-truncate">{{ $r['name'] ?: $r['email'] }}</div>
                            <div class="small text-body-secondary text-truncate">
                                {{ $r['email'] }}@if ($r['hint'])
                                    · {{ $r['hint'] }}
                                @endif
                                @if ($searching && $r['source'])
                                    · <em>{{ $r['source'] }}</em>
                                @endif
                            </div>
                        </button>
                        <button type="button"
                            class="btn btn-link btn-sm p-0 text-decoration-none {{ $fav ? 'text-warning' : 'text-body-tertiary' }}"
                            wire:click="toggleFavorite(@js($r['email']))"
                            title="{{ $fav ? 'Aus meinen Favoriten entfernen' : 'Zu meinen Favoriten' }}">
                            <i class="bi {{ $fav ? 'bi-star-fill' : 'bi-star' }}"></i>
                        </button>
                        @if ($in)
                            <span class="badge text-bg-primary">{{ $fields[$in] }}</span>
                        @else
                            <div class="btn-group btn-group-sm nfm-quick">
                                @foreach ($fields as $target => $targetLabel)
                                    <button type="button" class="btn btn-outline-secondary"
                                        wire:click="add(@js($r['email']), '{{ $target }}')">{{ $targetLabel }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="list-group-item small text-body-secondary">Keine Treffer.</div>
                @endforelse
            @empty
                <div class="list-group-item small text-body-secondary py-4 text-center">
                    Suchbegriff eingeben (ab {{ config('nfmailer.search.min_chars', 2) }} Zeichen)<br>
                    oder Adresse im Formular direkt eintippen.
                </div>
            @endforelse
        </div>
    </div>

    <div class="border-top p-2 d-flex align-items-center gap-2" x-show="$wire.picked.length" x-cloak>
        <span class="small me-auto"><span x-text="$wire.picked.length"></span> markiert</span>
        <button type="button" class="btn btn-sm btn-link text-decoration-none" x-on:click="$wire.picked = []">Aufheben</button>
        <div class="btn-group btn-group-sm">
            @foreach ($fields as $target => $targetLabel)
                <button type="button" class="btn btn-primary" wire:click="addPicked('{{ $target }}')">→
                    {{ $targetLabel }}</button>
            @endforeach
        </div>
    </div>
</div>
