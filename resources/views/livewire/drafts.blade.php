{{-- Entwürfe: Öffnen im E-Mail-Modal (nfmailer-open mit 'draft'), Duplizieren, Löschen --}}
<div>
    @unless ($compact)
        <div class="d-flex align-items-center gap-2 mb-3">
            <input type="search" class="form-control" style="max-width: 24rem" placeholder="Empfänger, Betreff oder Akte suchen …"
                wire:model.live.debounce.300ms="search" autocomplete="off">
            <span class="small text-body-secondary ms-auto">
                {{ count($this->drafts) }} {{ count($this->drafts) === 1 ? 'Entwurf' : 'Entwürfe' }}
            </span>
        </div>
    @endunless

    <div class="list-group">
        @forelse ($this->drafts as $d)
            <div class="list-group-item d-flex align-items-center gap-3" wire:key="draft-{{ $d['id'] }}">
                <button type="button" class="btn btn-link p-0 text-start text-body text-decoration-none flex-grow-1 min-w-0"
                    x-on:click="Livewire.dispatch('nfmailer-open', { options: { draft: {{ $d['id'] }} } })"
                    title="Entwurf öffnen">
                    <div class="d-flex gap-2">
                        <span class="fw-medium text-truncate">{{ $d['subject'] !== '' ? $d['subject'] : '(ohne Betreff)' }}</span>
                        @if ($d['attachments'])
                            <span class="text-body-secondary small text-nowrap"><i class="bi bi-paperclip"></i>{{ $d['attachments'] }}</span>
                        @endif
                    </div>
                    <div class="small text-body-secondary text-truncate">
                        {{ $d['to'] !== '' ? 'An '.$d['to'] : 'noch kein Empfänger' }}
                        @if (! $compact && $d['context'])
                            · {{ $d['context'] }}
                        @endif
                        @unless ($d['mine'])
                            · <em>von anderem Bearbeiter</em>
                        @endunless
                    </div>
                </button>
                <span class="small text-body-secondary text-nowrap d-none d-sm-inline">{{ $d['updated'] }}</span>
                <div class="btn-group btn-group-sm">
                    <button type="button" class="btn btn-outline-secondary" title="Als neuen Entwurf kopieren"
                        wire:click="duplicate({{ $d['id'] }})"><i class="bi bi-copy"></i></button>
                    <button type="button" class="btn btn-outline-danger" title="Löschen"
                        wire:click="delete({{ $d['id'] }})" wire:confirm="Entwurf löschen?"><i class="bi bi-trash"></i></button>
                </div>
            </div>
        @empty
            <div class="list-group-item small text-body-secondary">
                {{ $search !== '' ? 'Keine Treffer.' : 'Keine Entwürfe.' }}
            </div>
        @endforelse
    </div>
</div>
