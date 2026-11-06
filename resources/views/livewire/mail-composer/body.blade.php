{{-- Betreff und Nachricht; KI-Bearbeitung und Diktat aus dem textbot-Package (nur wenn installiert) --}}
<div class="mb-2">
    <input type="text" class="form-control @error('subject') is-invalid @enderror" placeholder="Betreff"
        wire:model="subject">
    @error('subject')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-2 nfm-body" x-data="{ sig: false }">
    {{-- Höhe folgt dem Fenster (Flex-Kette in assets: modal-content → modal-body → nfm-compose → nfm-body) --}}
    <textarea id="nfmailer-body" class="form-control nfm-body-text @error('body') is-invalid @enderror" rows="8"
        placeholder="Nachricht …" wire:model="body"
        x-on:nfmailer-insert-transcript.window="nfmailerInsert(($event.detail.text ?? '').trim() + ' ')"></textarea>
    @error('body')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror

    @if (config('nfmailer.signature') && $useSignature)
        <div class="form-text border-start ps-2 mt-1" style="white-space: pre-line" x-show="sig" x-cloak>– – –
            {{ config('nfmailer.signature') }}</div>
    @endif

    <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
        @if ($this->textbotAvailable)
            <button type="button" wire:click="enhance" wire:loading.attr="disabled" wire:target="enhance"
                class="btn btn-outline-primary btn-sm"
                title="Nachricht per KI überarbeiten ({{ config('textbot.modes.' . config('textbot.defaults.mode') . '.label', 'Standardmodus') }})">
                <span wire:loading.remove wire:target="enhance"><i class="bi bi-stars me-1"></i> KI ausführen</span>
                <span wire:loading wire:target="enhance">
                    <span class="spinner-border spinner-border-sm me-1" role="status"></span> Wird bearbeitet …
                </span>
            </button>

            @include('textbot::livewire.partials.dictation', ['insert' => 'nfmailerInsert'])
        @endif

        <div class="d-flex align-items-center gap-2 ms-auto">

            {{-- Gesendete Mail ablegen: der mailer löst nach dem Versand MailSent aus, die Anwendung legt ab --}}
            @if (config('nfmailer.archive.enabled', false))
                <div class="form-check form-switch nfm-switch" title="{{ config('nfmailer.archive.label') }}">
                    <input class="form-check-input" type="checkbox" role="switch" id="nfmArchive"
                        wire:model.live="archive">
                    &nbsp;
                    <label class="form-check-label" for="nfmArchive">
                        <i class="bi {{ $archive ? 'bi-archive-fill text-success' : 'bi-archive' }}"></i><span
                            class="d-none d-sm-inline ms-1">Ablegen</span>
                    </label>
                </div>
            @endif

            @if (config('nfmailer.signature'))
                <div class="form-check form-switch nfm-switch" title="Signatur anhängen">
                    <input class="form-check-input" type="checkbox" role="switch" id="nfmSignature"
                        wire:model.live="useSignature">
                    &nbsp;
                    <label class="form-check-label" for="nfmSignature">
                        <i class="bi {{ $useSignature ? 'bi-pen-fill text-success' : 'bi-pen' }}"></i><span
                            class="d-none d-sm-inline ms-1">Signatur</span>
                    </label>
                    @if ($useSignature)
                        <button type="button"
                            class="btn btn-link btn-sm text-decoration-none text-body-secondary p-0 ms-1"
                            x-on:click="sig = !sig" :aria-expanded="sig" title="Signatur anzeigen">
                            <i class="bi" :class="sig ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                        </button>
                    @endif
                </div>
            @endif

        </div>

    </div>
</div>
