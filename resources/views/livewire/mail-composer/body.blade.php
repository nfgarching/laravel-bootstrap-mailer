{{-- Betreff und Nachricht; KI-Bearbeitung und Diktat aus dem textbot-Package (nur wenn installiert) --}}
<div class="mb-2">
    <input type="text" class="form-control @error('subject') is-invalid @enderror" placeholder="Betreff"
        wire:model="subject">
    @error('subject')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-2 nfm-body" x-data="{ sig: false }">
    @php
        $bodyClass = 'nfm-body-text' . ($errors->has('body') ? ' is-invalid' : '');
    @endphp

    {{-- Textfeld mit Markdown-Toolbar und Vorschau aus dem textbot-Package (x-markdown-textarea);
         ohne textbot ein schlichtes Textfeld mit eigener Vorschau. Höhe folgt dem Fenster
         (Flex-Kette in assets: modal-content → modal-body → nfm-compose → nfm-body) --}}
    @if ($this->textbotAvailable)
        <x-dynamic-component component="markdown-textarea" id="nfmailer-body" :preview="$preview"
            :preview-html="$preview ? $this->previewHtml : ''" preview-class="nfm-body-text nfm-body-preview"
            preview-title="Nachricht so anzeigen, wie sie versendet wird" :class="$bodyClass" rows="8"
            placeholder="Nachricht …" wire:model="body" />
    @else
        <div class="d-flex mb-1">
            <div class="ms-auto">@include('nfmailer::livewire.mail-composer.preview-toggle')</div>
        </div>
        <textarea id="nfmailer-body" class="form-control {{ $bodyClass }} @if ($preview) d-none @endif"
            rows="8" placeholder="Nachricht …" wire:model="body"></textarea>
        @if ($preview)
            <div class="form-control nfm-body-text nfm-body-preview">
                @if (filled(trim($body)))
                    {!! $this->previewHtml !!}
                @else
                    <span class="text-body-secondary">Kein Text vorhanden.</span>
                @endif
            </div>
        @endif
    @endif
    @error('body')
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @enderror

    @if (config('nfmailer.signature') && $useSignature)
        <div class="form-text border-start ps-2 mt-1" style="white-space: pre-line" x-show="sig" x-cloak>– – –
            {{ config('nfmailer.signature') }}</div>
    @endif

    <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
        @if ($this->textbotAvailable)
            {{-- Split-Button des textbot: KI ausführen, Modus/Provider/Modell im Panel --}}
            <x-dynamic-component component="ai-button" variant="outline-primary" :modes="$this->aiModes"
                :providers="$this->aiProviders" :mode="$mode" :provider="$provider" :aimodel="$aimodel"
                :usage="$this->aiUsage" />
        @endif

        {{-- Diktat: Live-Diktat immer, KI-Transkription nur mit gebundenem AudioTranscriber (textbot) --}}
        <x-dictation target="#nfmailer-body" transcribe />

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
