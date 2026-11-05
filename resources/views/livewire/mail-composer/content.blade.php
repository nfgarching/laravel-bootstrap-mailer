{{-- Kopf, Inhalt und Footer – identisch für Modal und eigenständige Seite --}}
@php $sec = $this->security; @endphp

<div class="modal-header">
    <button type="button" class="btn btn-link btn-sm p-0 me-2 text-decoration-none" x-show="view === 'options'" x-cloak
        x-on:click="view = 'compose'" title="Zurück zur Nachricht">
        <i class="bi bi-arrow-left fs-5"></i>
    </button>
    <h5 class="modal-title" id="nfmailerTitle"
        x-text="view === 'options' ? 'Versandoptionen' : 'E-Mail verfassen'">E-Mail verfassen</h5>
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

    @if ($result)
        @include('nfmailer::livewire.mail-composer.result')
    @else
        <div x-show="view === 'compose'">
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
