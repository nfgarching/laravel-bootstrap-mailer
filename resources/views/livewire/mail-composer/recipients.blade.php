{{-- Empfängerfelder An/CC/BCC: Chips, freie Eingabe mit Inline-Vorschlägen, Knopf zum Adressbuch.
     CC/BCC erscheinen erst auf Wunsch oder wenn sie belegt bzw. aktiv sind. --}}
@php
    $copiesVisible = $showCopies || count($cc) || count($bcc) || $activeField !== 'to';
@endphp

@foreach ($fields as $field => $label)
    @continue($field !== 'to' && !$copiesVisible)
    @php $active = $activeField === $field; @endphp

    <div class="nfm-field border rounded px-2 py-1 mb-2 d-flex align-items-start gap-2 {{ $active ? 'nfm-active' : '' }}"
        wire:click="setActive('{{ $field }}')" role="button" wire:key="field-{{ $field }}">
        <span
            class="nfm-label fw-semibold small pt-1 {{ $active ? 'text-primary' : 'text-body-secondary' }}">{{ $label }}</span>

        <div class="d-flex flex-wrap align-items-center gap-1 flex-grow-1 py-1 min-w-0">
            @foreach ($this->{$field} as $r)
                <span class="nfm-chip badge rounded-pill d-inline-flex align-items-center gap-1"
                    title="{{ $r['email'] }}{{ $r['hint'] ? ' · ' . $r['hint'] : '' }}"
                    wire:key="chip-{{ $field }}-{{ $r['email'] }}">
                    @if ($sec['canEncrypt'])
                        @if ($sec['certs'][mb_strtolower($r['email'])] ?? false)
                            <i class="bi bi-lock-fill text-success" title="Zertifikat vorhanden – verschlüsselbar"></i>
                        @else
                            <i class="bi bi-unlock text-body-tertiary"
                                title="Nicht verschlüsselbar – Details unter Versandoptionen"></i>
                        @endif
                    @endif
                    <span class="dropdown">
                        <button type="button" class="btn btn-link p-0 text-reset text-decoration-none nfm-chip-text"
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
                <div class="nfm-free-wrap position-relative flex-grow-1" x-data="{ hi: -1, open: true }">
                    <input type="text" class="nfm-free form-control form-control-sm border-0 shadow-none w-100 px-1"
                        placeholder="{{ count($this->{$field}) ? '' : 'Name oder Adresse eingeben …' }}"
                        autocomplete="off" spellcheck="false" wire:model.live.debounce.250ms="freeInput"
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
                        <div class="dropdown-menu show shadow-sm w-100 nfm-inline" x-ref="menu" x-show="open">
                            @foreach ($hits as $i => $r)
                                <button type="button" class="dropdown-item py-1" data-email="{{ $r['email'] }}"
                                    wire:key="inline-{{ $r['email'] }}" :class="{ active: hi === {{ $i }} }"
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
            @elseif (!count($this->{$field}))
                <span class="small text-body-tertiary">–</span>
            @endif
        </div>

        <div class="d-flex align-items-center gap-1 flex-shrink-0">
            @if ($field === 'to' && !$copiesVisible)
                <button type="button" class="btn btn-link btn-sm text-decoration-none px-1"
                    wire:click.stop="showCopyFields">CC/BCC</button>
            @endif
            <button type="button" class="btn btn-sm nfm-book {{ $active ? 'btn-outline-primary' : 'btn-link text-body-secondary' }}"
                data-bs-toggle="offcanvas" data-bs-target="#nfmAddressbook" aria-controls="nfmAddressbook"
                title="Adressbuch – übernimmt nach {{ $label }}">
                <i class="bi bi-person-lines-fill"></i>
            </button>
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
