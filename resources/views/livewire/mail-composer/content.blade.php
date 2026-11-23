{{-- Kopf, Inhalt und Footer – identisch für Modal und eigenständige Seite --}}
@php $sec = $this->security; @endphp

<div class="modal-header">
    <button type="button" class="btn btn-link btn-sm p-0 me-2 text-decoration-none" x-show="view === 'options'" x-cloak
        x-on:click="view = 'compose'" title="Zurück zur Nachricht">
        <i class="bi bi-arrow-left fs-5"></i>
    </button>
    <h5 class="modal-title" id="nfmailerTitle"
        x-text="view === 'options' ? 'Versandoptionen' : 'E-Mail verfassen'">E-Mail verfassen</h5>
    <small class="text-muted ms-3 text-truncate">{{ $this->contextLabel ?? 'ohne Zuordnung' }}</small>
    @if ($page)
        {{-- Im iframe-Modal schließt das ✕ des Modals (frame-hide) --}}
        <button type="button" class="btn-close frame-hide" aria-label="Schließen" onclick="closePopup()"></button>
    @else
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
    @endif
</div>

<div class="modal-body">
    @if ($notice)
        <div class="alert {{ $draftConflict ? 'alert-warning' : 'alert-danger' }} py-2 d-flex flex-wrap align-items-center gap-2">
            <span class="me-auto">{{ $notice }}</span>
            @if ($draftConflict === 'changed')
                <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="reloadDraft"
                    wire:confirm="Die Fassung aus dem anderen Fenster laden? Ihre Änderungen hier gehen verloren.">
                    Andere Fassung laden</button>
                <button type="button" class="btn btn-sm btn-warning" wire:click="saveDraft(true)">Meine Fassung speichern</button>
            @elseif ($draftConflict === 'gone')
                <button type="button" class="btn btn-sm btn-warning" wire:click="saveAsNewDraft">Als neuen Entwurf speichern</button>
            @endif
        </div>
    @endif

    @if ($result)
        @include('nfmailer::livewire.mail-composer.result')
    @else
        <div class="nfm-compose" x-show="view === 'compose'">
            @include('nfmailer::livewire.mail-composer.recipients')
            @include('nfmailer::livewire.mail-composer.body')
            @include('nfmailer::livewire.mail-composer.attachments')
        </div>

        <div x-show="view === 'options'" x-cloak>
            @include('nfmailer::livewire.mail-composer.options')
        </div>
    @endif
</div>

<div class="modal-footer">
    @include('nfmailer::livewire.mail-composer.footer')
</div>

{{-- Entwurf automatisch speichern (nur sichtbar = Modal offen bzw. Seite aktiv; nur bei Änderungen) --}}
@if (config('nfmailer.drafts.enabled', true) && config('nfmailer.drafts.autosave', true) && ! $result)
    <div class="d-none" wire:poll.{{ max(10, (int) config('nfmailer.drafts.autosave_seconds', 30)) }}s.visible="autosave"></div>
@endif
