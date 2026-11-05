{{-- Anhänge als Chips; Auswahl aus Akte bzw. übergebenen Anhängen im Offcanvas „Anhänge“ (sources) --}}
@php
    $activeAttachments = array_filter($attachments, fn ($a) => $a['active'] ?? true);
    $presets = array_filter($attachments, fn ($a) => $a['origin'] === 'preset');
    $presetsActive = count(array_filter($presets, fn ($a) => $a['active'] ?? true));
    $hasSources = $context && config('nfmailer.attachment_sources');
@endphp

<div class="d-flex flex-wrap align-items-center gap-1 pt-2 border-top">
    <i class="bi bi-paperclip text-body-secondary me-1" title="Anhänge"></i>

    @foreach ($activeAttachments as $i => $a)
        <span class="nfm-chip badge rounded-pill d-inline-flex align-items-center gap-1"
            wire:key="att-{{ $i }}-{{ $a['path'] }}" title="{{ $a['name'] }}">
            <span class="nfm-chip-text">{{ $a['name'] }}</span>
            <span class="text-body-secondary fw-normal">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($a['size']) }}</span>
            <button type="button" class="btn-close nfm-chip-x" aria-label="Entfernen"
                wire:click="removeAttachment({{ $i }})"></button>
        </span>
    @endforeach
    @foreach ($uploads as $i => $u)
        <span class="nfm-chip badge rounded-pill d-inline-flex align-items-center gap-1"
            wire:key="up-{{ $i }}-{{ $u->getFilename() }}" title="{{ $u->getClientOriginalName() }}">
            <span class="nfm-chip-text">{{ $u->getClientOriginalName() }}</span>
            <span class="text-body-secondary fw-normal">{{ \Nfgarching\Mailer\Support\AttachmentGuard::humanSize($u->getSize()) }}</span>
            <button type="button" class="btn-close nfm-chip-x" aria-label="Entfernen"
                wire:click="removeUpload({{ $i }})"></button>
        </span>
    @endforeach

    @if (!count($activeAttachments) && !count($uploads))
        <span class="small text-body-tertiary me-1">Keine Anhänge</span>
    @endif

    <span wire:loading wire:target="newUploads" class="small text-body-secondary">
        <span class="spinner-border spinner-border-sm"></span> Lädt hoch …
    </span>

    <div class="ms-auto d-flex gap-1">
        @if ($hasSources || count($presets))
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="offcanvas"
                data-bs-target="#nfmSources" aria-controls="nfmSources">
                <i class="bi bi-folder2-open me-1"></i>{{ $hasSources ? 'Aus der Akte' : ($attachmentsLabel ?: 'Übergebene Anhänge') }}
                @if (count($presets))
                    <span class="badge text-bg-secondary ms-1">{{ $presetsActive }}/{{ count($presets) }}</span>
                @endif
            </button>
        @endif
        <label class="btn btn-sm btn-outline-secondary mb-0">
            <i class="bi bi-upload me-1"></i>Datei
            <input type="file" class="d-none" multiple wire:model="newUploads">
        </label>
    </div>
</div>

@error('newUploads.*')
    <div class="text-danger small mt-1">{{ $message }}</div>
@enderror
