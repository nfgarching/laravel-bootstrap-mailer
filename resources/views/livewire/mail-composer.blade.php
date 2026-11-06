<div>
    @if ($page)
        {{-- Eigenständige Seite (Route nfmailer.compose, Popup oder Tab): kein Modal;
     nach Versand bzw. Übergabe ans Mailprogramm schließt sich das Fenster --}}
        <div class="nfm container-xl py-3" id="nfmailerPage" x-data x-init="$el.querySelector('.nfm-free')?.focus()"
            x-on:nfmailer-hide.window="setTimeout(() => window.close(), 500)">
            <div>
                <div class="modal-content border rounded">
                @else
                    <div class="modal fade nfm" id="nfmailerModal" tabindex="-1" aria-labelledby="nfmailerTitle"
                        aria-hidden="true" wire:ignore.self x-data
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

        @php $sec = $this->security; @endphp

        @if ($result)
            {{-- ============================================ Nach Versand mit PIN --}}
            <div class="text-center py-4 mx-auto" style="max-width: 30rem">
                <i class="bi bi-check-circle text-success display-5"></i>
                <h5 class="mt-2">E-Mail versandt</h5>
                <p class="text-body-secondary small mb-3">
                    @if ($result['signed'])
                        <span class="badge text-bg-light border"><i class="bi bi-patch-check"></i> signiert</span>
                    @endif
                    @if ($result['encrypted'])
                        <span class="badge text-bg-light border"><i class="bi bi-lock-fill"></i> verschlüsselt</span>
                    @endif
                </p>
                <div class="border rounded p-3 bg-body-tertiary">
                    <div class="small text-body-secondary">PIN für die Download-Links</div>
                    <div class="fs-2 fw-bold font-monospace user-select-all" style="letter-spacing: .3rem">
                        {{ $result['pin'] }}</div>
                    <div class="small mt-1">
                        @if ($result['pinMailed'])
                            Die PIN wurde zusätzlich in einer separaten E-Mail versandt.
                        @else
                            Bitte teilen Sie die PIN dem Empfänger telefonisch oder per SMS mit.
                        @endif
                    </div>
                </div>
                <p class="small text-body-secondary mt-3 mb-0">Die PIN bleibt im Versandprotokoll (verschlüsselt)
                    abrufbar.</p>
            </div>
        @else
            <div class="row g-3">

                {{-- ============================================ Linke Spalte: Mail --}}
                <div class="col-lg-7">

                    {{-- Empfängerfelder --}}
                    @foreach ($fields as $field => $label)
                        @php $active = $activeField === $field; @endphp
                        <div class="nfm-field border rounded px-2 py-1 mb-2 d-flex align-items-start gap-2 {{ $active ? 'nfm-active' : '' }}"
                            wire:click="setActive('{{ $field }}')" role="button"
                            wire:key="field-{{ $field }}">
                            <span
                                class="nfm-label fw-semibold small pt-1 {{ $active ? 'text-primary' : 'text-body-secondary' }}">{{ $label }}</span>

                            <div class="d-flex flex-wrap align-items-center gap-1 flex-grow-1 py-1">
                                @foreach ($this->{$field} as $r)
                                    <span class="nfm-chip badge rounded-pill d-inline-flex align-items-center gap-1"
                                        title="{{ $r['email'] }}{{ $r['hint'] ? ' · ' . $r['hint'] : '' }}"
                                        wire:key="chip-{{ $field }}-{{ $r['email'] }}">
                                        @if ($sec['canEncrypt'])
                                            @if ($sec['certs'][mb_strtolower($r['email'])] ?? false)
                                                <i class="bi bi-lock-fill text-success"
                                                    title="Zertifikat vorhanden – verschlüsselbar"></i>
                                            @else
                                                <i class="bi bi-unlock text-body-tertiary"
                                                    title="Nicht verschlüsselbar – Grund siehe unten"></i>
                                            @endif
                                        @endif
                                        <span class="dropdown">
                                            <button type="button"
                                                class="btn btn-link p-0 text-reset text-decoration-none nfm-chip-text"
                                                data-bs-toggle="dropdown" aria-expanded="false">
                                                {{ $r['name'] ?: $r['email'] }}
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-sm shadow-sm">
                                                <li>
                                                    <h6 class="dropdown-header">{{ $r['email'] }}</h6>
                                                </li>
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
                                    @php $hits = $this->inlineResults; @endphp
                                    <div class="nfm-free-wrap position-relative flex-grow-1"
                                        x-data="{ hi: -1, open: true }">
                                        <input type="text"
                                            class="nfm-free form-control form-control-sm border-0 shadow-none w-100 px-1"
                                            placeholder="{{ count($this->{$field}) ? '' : 'Name oder Adresse eingeben oder rechts auswählen …' }}"
                                            autocomplete="off" spellcheck="false"
                                            wire:model.live.debounce.250ms="freeInput"
                                            x-on:input="hi = -1; open = true"
                                            x-on:keydown.down.prevent="open = true; hi = Math.min(hi + 1, ($refs.menu?.querySelectorAll('[data-email]').length ?? 0) - 1)"
                                            x-on:keydown.up.prevent="hi = Math.max(hi - 1, -1)"
                                            x-on:keydown.escape.stop="hi = -1; open = false; $wire.set('freeInput', '')"
                                            x-on:keydown.enter.prevent="const el = open && $refs.menu?.querySelectorAll('[data-email]')[hi]; el ? $wire.addInline(el.dataset.email) : $wire.addFree(); hi = -1; open = false"
                                            x-on:keydown="if ([',', ';'].includes($event.key)) { $event.preventDefault(); $wire.addFree() }"
                                            x-on:keydown.backspace="if (!$el.value) $wire.removeLast()"
                                            x-on:paste="setTimeout(() => $wire.addFree(), 0)"
                                            x-on:blur="if ($el.value.trim()) $wire.addFree()">

                                        {{-- Treffer zur Eingabe; mousedown.prevent hält den Fokus im Feld (sonst blur → addFree) --}}
                                        @if (count($hits))
                                            <div class="dropdown-menu show shadow-sm w-100 nfm-inline" x-ref="menu"
                                                x-show="open">
                                                @foreach ($hits as $i => $r)
                                                    <button type="button" class="dropdown-item py-1"
                                                        data-email="{{ $r['email'] }}"
                                                        wire:key="inline-{{ $r['email'] }}"
                                                        :class="{ active: hi === {{ $i }} }"
                                                        x-on:mouseenter="hi = {{ $i }}" x-on:mousedown.prevent
                                                        x-on:click="open = false; hi = -1"
                                                        wire:click.stop="addInline(@js($r['email']))">
                                                        <div class="fw-medium text-truncate">{{ $r['name'] ?: $r['email'] }}</div>
                                                        <div class="small text-truncate opacity-75">
                                                            {{ $r['email'] }}@if ($r['hint'])
                                                                · {{ $r['hint'] }}
                                                            @endif
                                                        </div>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    @if (!count($this->{$field}))
                                        <span class="small text-body-tertiary">–</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                        @if ($active)
                            @error('freeInput')
                                <div class="text-danger small mt-n1 mb-2">{{ $message }}</div>
                            @enderror
                        @endif
                    @endforeach
                    @error('to')
                        <div class="text-danger small mb-2">{{ $message }}</div>
                    @enderror

                    {{-- Betreff --}}
                    <div class="mb-2">
                        <input type="text" class="form-control @error('subject') is-invalid @enderror"
                            placeholder="Betreff" wire:model="subject">
                        @error('subject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- Text --}}
                    <div class="mb-2">
                        <textarea class="form-control @error('body') is-invalid @enderror" rows="10" placeholder="Nachricht …"
                            wire:model="body"></textarea>
                        @error('body')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        @if (config('nfmailer.signature'))
                            <div class="form-text" style="white-space: pre-line">– – –
                                {{ config('nfmailer.signature') }}
                            </div>
                        @endif
                    </div>

                    {{-- Anhänge --}}
                    <div class="border rounded p-2">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="fw-semibold small">Anhänge</span>
                            @if ($context && config('nfmailer.attachment_sources'))
                                <button type="button"
                                    class="btn btn-sm {{ $showSources ? 'btn-secondary' : 'btn-outline-secondary' }} ms-auto"
                                    wire:click="toggleSources">
                                    <i class="bi bi-folder2-open me-1"></i>Aus der Akte
                                </button>
                            @endif
                            <label
                                class="btn btn-sm btn-outline-secondary mb-0 {{ $context && config('nfmailer.attachment_sources') ? '' : 'ms-auto' }}">
                                Datei hinzufügen
                                <input type="file" class="d-none" multiple wire:model="newUploads">
                            </label>
                        </div>

                        {{-- Auswahl aus den Anhang-Quellen (z. B. Dokumente der Akte) --}}
                        @if ($showSources)
                            @php
                                $sourceFiles = $this->sourceFiles;
                                $attached = collect($attachments)
                                    ->filter(fn ($a) => $a['active'] ?? true)
                                    ->mapWithKeys(fn ($a) => [$a['disk'] . ':' . $a['path'] => true]);
                            @endphp
                            <div class="border rounded mb-2">
                                <div class="p-2 border-bottom">
                                    <input type="search" class="form-control form-control-sm"
                                        placeholder="Dokument oder Datei suchen …" autocomplete="off"
                                        wire:model.live.debounce.300ms="sourceSearch" x-on:keydown.escape.stop>
                                </div>

                                <div class="list-group list-group-flush overflow-auto nfm-sources"
                                    wire:loading.class="opacity-50" wire:target="sourceSearch">
                                    @forelse ($sourceFiles as $sourceLabel => $files)
                                        <div class="list-group-item bg-body-tertiary py-1 small fw-semibold"
                                            wire:key="src-{{ $sourceLabel }}">{{ $sourceLabel }}</div>
                                        @php $group = null; @endphp
                                        @foreach ($files as $f)
                                            @if ($f['group'] !== $group)
                                                @php $group = $f['group']; @endphp
                                                <div class="list-group-item py-1 small text-body-secondary"
                                                    wire:key="srcgrp-{{ $f['key'] }}">{{ $group }}</div>
                                            @endif
                                            @php $in = isset($attached[$f['disk'] . ':' . $f['path']]); @endphp
                                            <label
                                                class="list-group-item d-flex align-items-center gap-2 py-1 ps-4 small {{ $in ? 'nfm-taken' : '' }}"
                                                wire:key="srcfile-{{ $f['key'] }}">
                                                <input class="form-check-input flex-shrink-0 m-0" type="checkbox"
                                                    value="{{ $f['key'] }}" wire:model="pickedFiles"
                                                    @disabled($in)>
                                                <span class="text-truncate">{{ $f['name'] }}</span>
                                                @if ($in)
                                                    <span class="badge text-bg-primary ms-auto">angehängt</span>
                                                @elseif ($f['size'])
                                                    <span
                                                        class="text-body-secondary text-nowrap ms-auto">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($f['size']) }}</span>
                                                @endif
                                            </label>
                                        @endforeach
                                    @empty
                                        <div class="list-group-item small text-body-secondary">
                                            {{ trim($sourceSearch) !== '' ? 'Keine Treffer.' : 'Keine Dokumente mit Anhängen.' }}
                                        </div>
                                    @endforelse
                                </div>

                                <div class="p-2 border-top d-flex align-items-center gap-2">
                                    <span class="small me-auto" x-text="$wire.pickedFiles.length + ' markiert'"></span>
                                    <button type="button" class="btn btn-sm btn-link text-decoration-none"
                                        wire:click="toggleSources">Schließen</button>
                                    <button type="button" class="btn btn-sm btn-primary" wire:click="addSourceFiles"
                                        x-bind:disabled="!$wire.pickedFiles.length">Anhängen</button>
                                </div>
                            </div>
                        @endif

                        <div wire:loading wire:target="newUploads" class="small text-body-secondary mb-1">
                            <span class="spinner-border spinner-border-sm"></span> Lädt hoch …
                        </div>
                        @error('newUploads.*')
                            <div class="text-danger small mb-1">{{ $message }}</div>
                        @enderror

                        @php $activeAttachments = array_filter($attachments, fn ($a) => $a['active'] ?? true); @endphp
                        @if (count($activeAttachments) || count($uploads))
                            <ul class="list-unstyled small mb-2">
                                @foreach ($activeAttachments as $i => $a)
                                    <li class="d-flex align-items-center gap-2 py-1 border-bottom"
                                        wire:key="att-{{ $i }}-{{ $a['path'] }}">
                                        <span class="text-truncate">{{ $a['name'] }}</span>
                                        <span
                                            class="text-body-secondary text-nowrap">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($a['size']) }}</span>
                                        <button type="button" class="btn-close btn-sm ms-auto"
                                            aria-label="Entfernen"
                                            wire:click="removeAttachment({{ $i }})"></button>
                                    </li>
                                @endforeach
                                @foreach ($uploads as $i => $u)
                                    <li class="d-flex align-items-center gap-2 py-1 border-bottom"
                                        wire:key="up-{{ $i }}-{{ $u->getFilename() }}">
                                        <span class="text-truncate">{{ $u->getClientOriginalName() }}</span>
                                        <span
                                            class="text-body-secondary text-nowrap">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($u->getSize()) }}</span>
                                        <button type="button" class="btn-close btn-sm ms-auto"
                                            aria-label="Entfernen"
                                            wire:click="removeUpload({{ $i }})"></button>
                                    </li>
                                @endforeach
                            </ul>

                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="nfmAsLinks"
                                    wire:model="asLinks">
                                <label class="form-check-label small" for="nfmAsLinks">
                                    Als Download-Link statt Anhang senden
                                    @if (config('nfmailer.links.expires_days'))
                                        <span class="text-body-secondary">(gültig
                                            {{ config('nfmailer.links.expires_days') }} Tage)</span>
                                    @endif
                                </label>
                            </div>

                            @if ($asLinks)
                                <div class="ms-4 mt-1">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                            id="nfmUsePin" wire:model.live="usePin">
                                        <label class="form-check-label small" for="nfmUsePin">Download mit PIN
                                            schützen</label>
                                    </div>
                                    @if ($usePin)
                                        <div class="form-check mb-0">
                                            <input class="form-check-input" type="checkbox" id="nfmPinByMail"
                                                wire:model="pinByMail">
                                            <label class="form-check-label small" for="nfmPinByMail">
                                                PIN zusätzlich per separater E-Mail senden
                                                <span class="text-body-secondary">(sonst telefonisch/SMS
                                                    mitteilen)</span>
                                            </label>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        @else
                            <div class="small text-body-tertiary">Keine Anhänge.</div>
                        @endif
                    </div>

                    {{-- Sicherheit --}}
                    @if ($sec['signs'] || $sec['canEncrypt'])
                        <div class="border rounded p-2 mt-2 small">
                            <div class="d-flex flex-wrap align-items-center gap-3">
                                @if ($sec['signs'])
                                    <span title="Die E-Mail wird mit dem S/MIME-Zertifikat der Kanzlei signiert">
                                        <i class="bi bi-patch-check text-success"></i> Wird signiert
                                    </span>
                                @endif

                                @if ($sec['canEncrypt'])
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                            id="nfmEncrypt" wire:model.live="encrypt" @disabled(!$sec['encryptPossible'])>
                                        <label class="form-check-label" for="nfmEncrypt">
                                            <i
                                                class="bi {{ $encrypt && $sec['encryptPossible'] ? 'bi-lock-fill text-success' : 'bi-unlock' }}"></i>
                                            Verschlüsseln (S/MIME)
                                        </label>
                                    </div>
                                @endif
                            </div>

                            @if ($sec['canEncrypt'] && $sec['missing'])
                                <div class="text-body-secondary mt-1">
                                    Verschlüsselung nicht möglich für:
                                    {{ implode(', ', $sec['missing']) }}
                                </div>
                                @if ($sec['canLookup'])
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1"
                                        wire:click="lookupCertificates" wire:loading.attr="disabled"
                                        wire:target="lookupCertificates"
                                        title="Sucht in den öffentlichen Verzeichnissen der Zertifizierungsstellen (D-Trust, DFN, TeleSec, SwissSign). Die Adressen werden dabei an diese Verzeichnisse übermittelt.">
                                        <span wire:loading.remove wire:target="lookupCertificates"><i
                                                class="bi bi-search"></i></span>
                                        <span wire:loading wire:target="lookupCertificates"
                                            class="spinner-border spinner-border-sm"></span>
                                        Zertifikate in öffentlichen Verzeichnissen suchen
                                    </button>
                                @endif
                            @elseif ($encrypt && $sec['encryptPossible'])
                                <div class="text-body-secondary mt-1">Hinweis: Der Betreff wird nicht verschlüsselt.
                                </div>
                            @endif

                            @if ($lookupResults)
                                <ul class="list-unstyled text-body-secondary mt-1 mb-0">
                                    @foreach ($lookupResults as $line)
                                        <li><i class="bi bi-search"></i> {{ $line }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

                    {{-- Ablage der gesendeten Mail (Auswertung durch die Anwendung, Ereignis MailSent) --}}
                    @if (config('nfmailer.archive.enabled'))
                        <div class="form-check form-switch mt-2 mb-0 small">
                            <input class="form-check-input" type="checkbox" role="switch" id="nfmArchive"
                                wire:model="archive">
                            <label class="form-check-label" for="nfmArchive">
                                <i class="bi bi-archive"></i> {{ config('nfmailer.archive.label') }}
                            </label>
                        </div>
                    @endif
                </div>

                {{-- ============================================ Rechte Spalte: Auswahl --}}
                <div class="col-lg-5 d-flex flex-column gap-2">
                    <div class="card flex-grow-1" x-data>
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

                        <div class="list-group list-group-flush overflow-auto nfm-list"
                            wire:loading.class="opacity-50" wire:target="search">
                            @forelse ($groups as $groupLabel => $items)
                                <div class="list-group-item bg-body-tertiary d-flex align-items-center py-1 small fw-semibold"
                                    wire:key="grp-{{ $groupLabel }}">
                                    <span>{{ $groupLabel }}</span>
                                    @if (count($items) > 1)
                                        <button type="button"
                                            class="btn btn-link btn-sm p-0 ms-auto text-decoration-none"
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

                        <div class="card-footer bg-body d-flex align-items-center gap-2" x-show="$wire.picked.length"
                            x-cloak>
                            <span class="small me-auto"><span x-text="$wire.picked.length"></span> markiert</span>
                            <button type="button" class="btn btn-sm btn-link text-decoration-none"
                                x-on:click="$wire.picked = []">Aufheben</button>
                            <div class="btn-group btn-group-sm">
                                @foreach ($fields as $target => $targetLabel)
                                    <button type="button" class="btn btn-primary"
                                        wire:click="addPicked('{{ $target }}')">→ {{ $targetLabel }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Übergebene Anhänge (z. B. des Dokuments): Häkchen = wird mitgesendet --}}
                    @php $presets = array_filter($attachments, fn ($a) => $a['origin'] === 'preset'); @endphp
                    @if (count($presets))
                        @php $presetsActive = count(array_filter($presets, fn ($a) => $a['active'] ?? true)); @endphp
                        <div class="card">
                            <div class="card-header bg-body-tertiary d-flex align-items-center py-1 small fw-semibold">
                                <span>{{ $attachmentsLabel ?: 'Übergebene Anhänge' }}</span>
                                <span
                                    class="text-body-secondary fw-normal ms-2">{{ $presetsActive . ' von ' . count($presets) }}</span>
                                @if (count($presets) > 1)
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-auto text-decoration-none"
                                        wire:click="setPresetAttachments({{ $presetsActive < count($presets) ? 'true' : 'false' }})">
                                        {{ $presetsActive < count($presets) ? 'alle' : 'keine' }}
                                    </button>
                                @endif
                            </div>
                            <div class="list-group list-group-flush overflow-auto nfm-presets">
                                @foreach ($presets as $i => $a)
                                    <label class="list-group-item d-flex align-items-center gap-2 py-1 small"
                                        wire:key="preset-{{ $i }}-{{ ($a['active'] ?? true) ? 'on' : 'off' }}">
                                        <input class="form-check-input flex-shrink-0 m-0" type="checkbox"
                                            wire:click="toggleAttachment({{ $i }})" @checked($a['active'] ?? true)>
                                        <span
                                            class="text-truncate {{ ($a['active'] ?? true) ? '' : 'text-body-tertiary text-decoration-line-through' }}">{{ $a['name'] }}</span>
                                        <span
                                            class="text-body-secondary text-nowrap ms-auto">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($a['size']) }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="modal-footer">
        @if ($result)
            <button type="button" class="btn btn-primary ms-auto" wire:click="closeResult">Schließen</button>
        @else
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
                title="Im Standard-Mailprogramm öffnen (ohne Anhänge, ohne Protokoll)" wire:loading.attr="disabled"
                wire:target="mailto,send,newUploads">
                <i class="bi bi-box-arrow-up-right me-1"></i>
                Im Mailprogramm öffnen
            </button>
            <button type="button" class="btn btn-primary" wire:click="send" wire:loading.attr="disabled"
                wire:target="send,newUploads">
                <span wire:loading wire:target="send" class="spinner-border spinner-border-sm me-1"></span>
                Senden
            </button>
        @endif
    </div>

</div>
</div>
</div>

<style>
    .nfm .nfm-field {
        min-height: 2.5rem;
        cursor: text;
        transition: border-color .15s, box-shadow .15s;
    }

    .nfm .nfm-active {
        border-color: var(--bs-primary) !important;
        box-shadow: 0 0 0 .2rem rgba(var(--bs-primary-rgb), .15);
    }

    .nfm .nfm-label {
        width: 2.4rem;
        flex-shrink: 0;
    }

    .nfm .nfm-free-wrap {
        min-width: 12rem;
    }

    .nfm .nfm-free {
        background: transparent;
    }

    .nfm .nfm-inline {
        top: 100%;
        left: 0;
        min-width: 20rem;
        max-height: 18rem;
        overflow-y: auto;
        z-index: 1060;
    }

    .nfm .nfm-chip {
        background: var(--bs-secondary-bg);
        color: var(--bs-body-color);
        border: 1px solid var(--bs-border-color);
        font-weight: 500;
        font-size: .85rem;
        padding: .3rem .4rem .3rem .65rem;
        max-width: 100%;
    }

    .nfm .nfm-chip-text {
        font-size: inherit;
        font-weight: inherit;
        max-width: 18rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .nfm .nfm-chip-x {
        font-size: .55rem;
        padding: .25rem;
    }

    .nfm .nfm-list {
        max-height: 55vh;
    }

    .nfm .nfm-presets {
        max-height: 12rem;
    }

    .nfm .nfm-sources {
        max-height: 16rem;
    }

    .nfm .nfm-taken {
        background: rgba(var(--bs-primary-rgb), .05);
    }

    .nfm .nfm-quick {
        opacity: 0;
        transition: opacity .1s;
    }

    .nfm .list-group-item:hover .nfm-quick,
    .nfm .nfm-quick:focus-within {
        opacity: 1;
    }

    .nfm .min-w-0 {
        min-width: 0;
    }

    @media (hover: none) {
        .nfm .nfm-quick {
            opacity: 1;
        }
    }

    [x-cloak] {
        display: none !important;
    }
</style>
</div>
