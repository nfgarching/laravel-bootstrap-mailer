<div>
@if ($page)
{{-- Eigenständige Seite (Route nfmailer.compose, Popup oder Tab): kein Modal;
     nach Versand bzw. Übergabe ans Mailprogramm schließt sich das Fenster --}}
<div class="nfm container-xl py-3" id="nfmailerPage"
     x-data
     x-init="$el.querySelector('.nfm-free')?.focus()"
     x-on:nfmailer-hide.window="setTimeout(() => window.close(), 500)">
  <div>
    <div class="modal-content border rounded">
@else
<div class="modal fade nfm" id="nfmailerModal" tabindex="-1" aria-labelledby="nfmailerTitle" aria-hidden="true"
     wire:ignore.self
     x-data
     x-on:nfmailer-show.window="bootstrap.Modal.getOrCreateInstance($el).show()"
     x-on:nfmailer-hide.window="bootstrap.Modal.getOrCreateInstance($el).hide()"
     x-on:shown-bs-modal.dot="$el.querySelector('.nfm-free')?.focus()">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
    <div class="modal-content">
@endif

      <div class="modal-header">
        <h5 class="modal-title" id="nfmailerTitle">E-Mail verfassen</h5>
        @if ($page)
          <button type="button" class="btn-close" aria-label="Schließen" onclick="window.close()"></button>
        @else
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
        @endif
      </div>

      <div class="modal-body">
        @if ($notice)
          <div class="alert alert-danger py-2">{{ $notice }}</div>
        @endif

        <div class="row g-3">

          {{-- ============================================ Linke Spalte: Mail --}}
          <div class="col-lg-7">

            {{-- Empfängerfelder --}}
            @foreach ($fields as $field => $label)
              @php $active = $activeField === $field; @endphp
              <div class="nfm-field border rounded px-2 py-1 mb-2 d-flex align-items-start gap-2 {{ $active ? 'nfm-active' : '' }}"
                   wire:click="setActive('{{ $field }}')" role="button"
                   wire:key="field-{{ $field }}">
                <span class="nfm-label fw-semibold small pt-1 {{ $active ? 'text-primary' : 'text-body-secondary' }}">{{ $label }}</span>

                <div class="d-flex flex-wrap align-items-center gap-1 flex-grow-1 py-1">
                  @foreach ($this->{$field} as $r)
                    <span class="nfm-chip badge rounded-pill d-inline-flex align-items-center gap-1"
                          title="{{ $r['email'] }}{{ $r['hint'] ? ' · '.$r['hint'] : '' }}"
                          wire:key="chip-{{ $field }}-{{ $r['email'] }}">
                      <span class="dropdown">
                        <button type="button" class="btn btn-link p-0 text-reset text-decoration-none nfm-chip-text"
                                data-bs-toggle="dropdown" aria-expanded="false">
                          {{ $r['name'] ?: $r['email'] }}
                        </button>
                        <ul class="dropdown-menu dropdown-menu-sm shadow-sm">
                          <li><h6 class="dropdown-header">{{ $r['email'] }}</h6></li>
                          @foreach ($fields as $target => $targetLabel)
                            @if ($target !== $field)
                              <li><button type="button" class="dropdown-item"
                                          wire:click.stop="move('{{ $field }}', @js($r['email']), '{{ $target }}')">
                                  Nach {{ $targetLabel }} verschieben</button></li>
                            @endif
                          @endforeach
                        </ul>
                      </span>
                      <button type="button" class="btn-close nfm-chip-x" aria-label="Entfernen"
                              wire:click.stop="remove('{{ $field }}', @js($r['email']))"></button>
                    </span>
                  @endforeach

                  @if ($active)
                    <input type="text" class="nfm-free form-control form-control-sm border-0 shadow-none flex-grow-1 px-1"
                           placeholder="{{ count($this->{$field}) ? '' : 'Adresse eingeben oder rechts auswählen …' }}"
                           autocomplete="off" spellcheck="false"
                           wire:model="freeInput"
                           x-on:keydown.enter.prevent="$wire.addFree()"
                           x-on:keydown="if ([',', ';'].includes($event.key)) { $event.preventDefault(); $wire.addFree() }"
                           x-on:keydown.backspace="if (!$el.value) $wire.removeLast()"
                           x-on:paste="setTimeout(() => $wire.addFree(), 0)"
                           x-on:blur="if ($el.value.trim()) $wire.addFree()">
                  @else
                    @if (! count($this->{$field}))
                      <span class="small text-body-tertiary">–</span>
                    @endif
                  @endif
                </div>
              </div>
              @if ($active)
                @error('freeInput') <div class="text-danger small mt-n1 mb-2">{{ $message }}</div> @enderror
              @endif
            @endforeach
            @error('to') <div class="text-danger small mb-2">{{ $message }}</div> @enderror

            {{-- Betreff --}}
            <div class="mb-2">
              <input type="text" class="form-control @error('subject') is-invalid @enderror"
                     placeholder="Betreff" wire:model="subject">
              @error('subject') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            {{-- Text --}}
            <div class="mb-2">
              <textarea class="form-control @error('body') is-invalid @enderror" rows="10"
                        placeholder="Nachricht …" wire:model="body"></textarea>
              @error('body') <div class="invalid-feedback">{{ $message }}</div> @enderror
              @if (config('nfmailer.signature'))
                <div class="form-text" style="white-space: pre-line">– – –
{{ config('nfmailer.signature') }}</div>
              @endif
            </div>

            {{-- Anhänge --}}
            <div class="border rounded p-2">
              <div class="d-flex align-items-center gap-2 mb-2">
                <span class="fw-semibold small">Anhänge</span>
                <label class="btn btn-sm btn-outline-secondary ms-auto mb-0">
                  Datei hinzufügen
                  <input type="file" class="d-none" multiple wire:model="newUploads">
                </label>
              </div>

              <div wire:loading wire:target="newUploads" class="small text-body-secondary mb-1">
                <span class="spinner-border spinner-border-sm"></span> Lädt hoch …
              </div>
              @error('newUploads.*') <div class="text-danger small mb-1">{{ $message }}</div> @enderror

              @if (count($attachments) || count($uploads))
                <ul class="list-unstyled small mb-2">
                  @foreach ($attachments as $i => $a)
                    <li class="d-flex align-items-center gap-2 py-1 border-bottom" wire:key="att-{{ $i }}-{{ $a['path'] }}">
                      <span class="text-truncate">{{ $a['name'] }}</span>
                      <span class="text-body-secondary text-nowrap">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($a['size']) }}</span>
                      <button type="button" class="btn-close btn-sm ms-auto" aria-label="Entfernen" wire:click="removeAttachment({{ $i }})"></button>
                    </li>
                  @endforeach
                  @foreach ($uploads as $i => $u)
                    <li class="d-flex align-items-center gap-2 py-1 border-bottom" wire:key="up-{{ $i }}-{{ $u->getFilename() }}">
                      <span class="text-truncate">{{ $u->getClientOriginalName() }}</span>
                      <span class="text-body-secondary text-nowrap">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($u->getSize()) }}</span>
                      <button type="button" class="btn-close btn-sm ms-auto" aria-label="Entfernen" wire:click="removeUpload({{ $i }})"></button>
                    </li>
                  @endforeach
                </ul>

                <div class="form-check form-switch mb-0">
                  <input class="form-check-input" type="checkbox" role="switch" id="nfmAsLinks" wire:model="asLinks">
                  <label class="form-check-label small" for="nfmAsLinks">
                    Als Download-Link statt Anhang senden
                    @if (config('nfmailer.links.expires_days'))
                      <span class="text-body-secondary">(gültig {{ config('nfmailer.links.expires_days') }} Tage)</span>
                    @endif
                  </label>
                </div>
              @else
                <div class="small text-body-tertiary">Keine Anhänge.</div>
              @endif
            </div>
          </div>

          {{-- ============================================ Rechte Spalte: Auswahl --}}
          <div class="col-lg-5">
            <div class="card h-100" x-data>
              <div class="card-header bg-body">
                <input type="search" class="form-control" placeholder="Name, Firma oder E-Mail suchen …"
                       wire:model.live.debounce.300ms="search" autocomplete="off">
                <div class="small text-body-secondary mt-1">
                  Klick übernimmt nach <strong class="text-primary">{{ $fields[$activeField] }}</strong>
                  – oder mehrere markieren.
                </div>
              </div>

              @php
                $searching = mb_strlen(trim($search)) >= (int) config('nfmailer.search.min_chars', 2);
                $groups = $searching ? ['Suchergebnisse' => $this->results] : $this->suggestions;
                $assigned = $this->assigned;
              @endphp

              <div class="list-group list-group-flush overflow-auto nfm-list" wire:loading.class="opacity-50" wire:target="search">
                @forelse ($groups as $groupLabel => $items)
                  <div class="list-group-item bg-body-tertiary d-flex align-items-center py-1 small fw-semibold"
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
                    @php $in = $assigned[mb_strtolower($r['email'])] ?? null; @endphp
                    <div class="list-group-item d-flex align-items-center gap-2 py-2 {{ $in ? 'nfm-taken' : '' }}"
                         wire:key="opt-{{ $groupLabel }}-{{ $r['email'] }}">
                      <input class="form-check-input flex-shrink-0 m-0" type="checkbox"
                             value="{{ $r['email'] }}" wire:model="picked" aria-label="markieren">
                      <button type="button" class="btn btn-link p-0 text-start text-body text-decoration-none flex-grow-1 min-w-0"
                              wire:click="add(@js($r['email']))">
                        <div class="fw-medium text-truncate">{{ $r['name'] ?: $r['email'] }}</div>
                        <div class="small text-body-secondary text-truncate">
                          {{ $r['email'] }}@if ($r['hint']) · {{ $r['hint'] }}@endif
                          @if ($searching && $r['source']) · <em>{{ $r['source'] }}</em>@endif
                        </div>
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
                    oder Adresse links direkt eintippen.
                  </div>
                @endforelse
              </div>

              <div class="card-footer bg-body d-flex align-items-center gap-2" x-show="$wire.picked.length" x-cloak>
                <span class="small me-auto"><span x-text="$wire.picked.length"></span> markiert</span>
                <button type="button" class="btn btn-sm btn-link text-decoration-none" x-on:click="$wire.picked = []">Aufheben</button>
                <div class="btn-group btn-group-sm">
                  @foreach ($fields as $target => $targetLabel)
                    <button type="button" class="btn btn-primary" wire:click="addPicked('{{ $target }}')">→ {{ $targetLabel }}</button>
                  @endforeach
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        @php $total = count($to) + count($cc) + count($bcc); @endphp
        <span class="small text-body-secondary me-auto">
          {{ $total }} Empfänger
          @if (count($cc) || count($bcc))
            ({{ count($to) }} An · {{ count($cc) }} CC · {{ count($bcc) }} BCC)
          @endif
        </span>
        <button type="button" class="btn btn-outline-secondary"
                @if ($page) onclick="window.close()" @else data-bs-dismiss="modal" @endif>Abbrechen</button>
        <button type="button" class="btn btn-outline-primary" wire:click="mailto"
                x-on:nfmailer-mailto.window="window.location.href = $event.detail.url"
                title="Im Standard-Mailprogramm öffnen (ohne Anhänge, ohne Protokoll)"
                wire:loading.attr="disabled" wire:target="mailto,send,newUploads">
          <i class="bi bi-box-arrow-up-right me-1"></i>
          Im Mailprogramm öffnen
        </button>
        <button type="button" class="btn btn-primary" wire:click="send"
                wire:loading.attr="disabled" wire:target="send,newUploads">
          <span wire:loading wire:target="send" class="spinner-border spinner-border-sm me-1"></span>
          Senden
        </button>
      </div>

    </div>
  </div>
</div>

<style>
  .nfm .nfm-field { min-height: 2.5rem; cursor: text; transition: border-color .15s, box-shadow .15s; }
  .nfm .nfm-active { border-color: var(--bs-primary) !important; box-shadow: 0 0 0 .2rem rgba(var(--bs-primary-rgb), .15); }
  .nfm .nfm-label { width: 2.4rem; flex-shrink: 0; }
  .nfm .nfm-free { min-width: 12rem; width: auto; background: transparent; }
  .nfm .nfm-chip { background: var(--bs-secondary-bg); color: var(--bs-body-color); border: 1px solid var(--bs-border-color);
                   font-weight: 500; font-size: .85rem; padding: .3rem .4rem .3rem .65rem; max-width: 100%; }
  .nfm .nfm-chip-text { font-size: inherit; font-weight: inherit; max-width: 18rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .nfm .nfm-chip-x { font-size: .55rem; padding: .25rem; }
  .nfm .nfm-list { max-height: 55vh; }
  .nfm .nfm-taken { background: rgba(var(--bs-primary-rgb), .05); }
  .nfm .nfm-quick { opacity: 0; transition: opacity .1s; }
  .nfm .list-group-item:hover .nfm-quick, .nfm .nfm-quick:focus-within { opacity: 1; }
  .nfm .min-w-0 { min-width: 0; }
  @media (hover: none) { .nfm .nfm-quick { opacity: 1; } }
  [x-cloak] { display: none !important; }
</style>
</div>
