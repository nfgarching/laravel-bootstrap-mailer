{{-- Offcanvas „Anhänge“: übergebene Anhänge (Häkchen = wird mitgesendet) und Dateien der Anhang-Quellen
     (z. B. Dokumente der Akte). Die Quellen werden erst beim Öffnen abgefragt (openSources). --}}
@php
    $presets = array_filter($attachments, fn ($a) => $a['origin'] === 'preset');
    $presetsActive = count(array_filter($presets, fn ($a) => $a['active'] ?? true));
    $hasSources = $context && config('nfmailer.attachment_sources');
@endphp

<div class="offcanvas offcanvas-end nfm-offcanvas" tabindex="-1" id="nfmSources" aria-labelledby="nfmSourcesTitle"
    data-bs-backdrop="false" data-bs-scroll="true" wire:ignore.self x-on:keydown.escape.stop
    x-on:show-bs-offcanvas.dot="if ($event.target === $el) $wire.openSources()"
    x-on:hidden-bs-offcanvas.dot="if ($event.target === $el) $wire.closeSources()"
    x-on:nfmailer-sources-hide.window="bootstrap.Offcanvas.getInstance($el)?.hide()">
    <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="nfmSourcesTitle"><i class="bi bi-paperclip me-1"></i> Anhänge auswählen</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Schließen"></button>
    </div>

    <div class="offcanvas-body p-0 d-flex flex-column">
        {{-- Übergebene Anhänge --}}
        @if (count($presets))
            <div class="border-bottom">
                <div class="bg-body-tertiary d-flex align-items-center px-3 py-1 small fw-semibold">
                    <span>{{ $attachmentsLabel ?: 'Übergebene Anhänge' }}</span>
                    <span class="text-body-secondary fw-normal ms-2">{{ $presetsActive . ' von ' . count($presets) }}</span>
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
                            wire:key="preset-{{ $i }}-{{ $a['active'] ?? true ? 'on' : 'off' }}">
                            <input class="form-check-input flex-shrink-0 m-0" type="checkbox"
                                wire:click="toggleAttachment({{ $i }})" @checked($a['active'] ?? true)>
                            <span
                                class="text-truncate {{ $a['active'] ?? true ? '' : 'text-body-tertiary text-decoration-line-through' }}">{{ $a['name'] }}</span>
                            <span
                                class="text-body-secondary text-nowrap ms-auto">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($a['size']) }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Dateien der Anhang-Quellen (Dokumente der Akte) --}}
        @if ($hasSources)
            @php
                $attached = collect($attachments)
                    ->filter(fn ($a) => $a['active'] ?? true)
                    ->mapWithKeys(fn ($a) => [$a['disk'] . ':' . $a['path'] => true]);
            @endphp
            <div class="bg-body-tertiary px-3 py-1 small fw-semibold border-bottom">Dokumente der Akte</div>
            <div class="p-2 border-bottom">
                <input type="search" class="form-control form-control-sm" placeholder="Dokument oder Datei suchen …"
                    autocomplete="off" wire:model.live.debounce.300ms="sourceSearch">
            </div>

            <div class="list-group list-group-flush overflow-auto flex-grow-1" wire:loading.class="opacity-50"
                wire:target="sourceSearch,openSources">
                @if (!$showSources)
                    <div class="list-group-item small text-body-secondary text-center py-4">
                        <span class="spinner-border spinner-border-sm"></span> Lädt …
                    </div>
                @else
                    @forelse ($this->sourceFiles as $sourceLabel => $files)
                        <div class="list-group-item bg-body-tertiary py-1 small fw-semibold" wire:key="src-{{ $sourceLabel }}">
                            {{ $sourceLabel }}</div>
                        @php $group = null; @endphp
                        @foreach ($files as $f)
                            @if ($f['group'] !== $group)
                                @php $group = $f['group']; @endphp
                                <div class="list-group-item py-1 small text-body-secondary" wire:key="srcgrp-{{ $f['key'] }}">
                                    {{ $group }}</div>
                            @endif
                            @php $in = isset($attached[$f['disk'] . ':' . $f['path']]); @endphp
                            <label
                                class="list-group-item d-flex align-items-center gap-2 py-1 ps-4 small {{ $in ? 'nfm-taken' : '' }}"
                                wire:key="srcfile-{{ $f['key'] }}">
                                <input class="form-check-input flex-shrink-0 m-0" type="checkbox" value="{{ $f['key'] }}"
                                    wire:model="pickedFiles" @disabled($in)>
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
                @endif
            </div>
        @endif
    </div>

    @if ($hasSources)
        <div class="border-top p-2 d-flex align-items-center gap-2">
            <span class="small me-auto" x-text="$wire.pickedFiles.length + ' markiert'"></span>
            <button type="button" class="btn btn-sm btn-link text-decoration-none" data-bs-dismiss="offcanvas">Schließen</button>
            <button type="button" class="btn btn-sm btn-primary" wire:click="addSourceFiles"
                x-bind:disabled="!$wire.pickedFiles.length">Anhängen</button>
        </div>
    @endif
</div>
