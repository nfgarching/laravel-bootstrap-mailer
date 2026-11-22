{{-- Betreff und Nachricht; KI-Bearbeitung und Diktat aus dem textbot-Package (nur wenn installiert) --}}
<div class="mb-2">
    <input type="text" class="form-control @error('subject') is-invalid @enderror" placeholder="Betreff"
        wire:model="subject">
    @error('subject')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-2 nfm-body" x-data="{ sig: false }">
    {{-- Formatierung: setzt Markdown-Zeichen um die Auswahl (nfmailerFormat in assets) --}}
    <div class="d-flex align-items-center gap-2 mb-1">
        <div class="btn-group btn-group-sm" role="group" aria-label="Formatierung">
            @foreach ([
                ['bold', 'bi-type-bold', 'Fett (Strg+B)'],
                ['italic', 'bi-type-italic', 'Kursiv (Strg+I)'],
                ['heading', 'bi-type-h3', 'Überschrift'],
                ['ul', 'bi-list-ul', 'Aufzählung'],
                ['ol', 'bi-list-ol', 'Nummerierte Liste'],
                ['quote', 'bi-quote', 'Zitat'],
                ['link', 'bi-link-45deg', 'Link (Strg+K)'],
            ] as [$kind, $icon, $title])
                <button type="button" class="btn btn-outline-secondary" title="{{ $title }}" aria-label="{{ $title }}"
                    @disabled($preview) x-on:click="nfmailerFormat('{{ $kind }}')">
                    <i class="bi {{ $icon }}"></i>
                </button>
            @endforeach
        </div>
        <span class="form-text m-0 d-none d-md-inline">Markdown: **fett**, _kursiv_, - Liste</span>
        <button type="button" class="btn btn-sm ms-auto {{ $preview ? 'btn-secondary' : 'btn-outline-secondary' }}"
            wire:click="$toggle('preview')" title="{{ $preview ? 'Zurück zum Bearbeiten' : 'Nachricht so anzeigen, wie sie versendet wird' }}">
            <i class="bi {{ $preview ? 'bi-pencil' : 'bi-eye' }} me-1"></i>{{ $preview ? 'Bearbeiten' : 'Vorschau' }}
        </button>
    </div>

    {{-- Höhe folgt dem Fenster (Flex-Kette in assets: modal-content → modal-body → nfm-compose → nfm-body) --}}
    <textarea id="nfmailer-body"
        class="form-control nfm-body-text @error('body') is-invalid @enderror @if ($preview) d-none @endif"
        rows="8" placeholder="Nachricht …" wire:model="body"
        x-on:keydown.ctrl.b.prevent="nfmailerFormat('bold')" x-on:keydown.ctrl.i.prevent="nfmailerFormat('italic')"
        x-on:keydown.ctrl.k.prevent="nfmailerFormat('link')"></textarea>
    @if ($preview)
        <div class="form-control nfm-body-text nfm-body-preview">
            @if (filled(trim($body)))
                {!! $this->previewHtml !!}
            @else
                <span class="text-body-secondary">Kein Text vorhanden.</span>
            @endif
        </div>
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
            <button type="button" wire:click="enhance" wire:loading.attr="disabled" wire:target="enhance"
                class="btn btn-outline-primary btn-sm"
                title="Nachricht per KI überarbeiten ({{ config('textbot.modes.' . config('textbot.defaults.mode') . '.label', 'Standardmodus') }})">
                <span wire:loading.remove wire:target="enhance"><i class="bi bi-stars me-1"></i> KI ausführen</span>
                <span wire:loading wire:target="enhance">
                    <span class="spinner-border spinner-border-sm me-1" role="status"></span> Wird bearbeitet …
                </span>
            </button>
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
