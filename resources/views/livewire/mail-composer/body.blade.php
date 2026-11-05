{{-- Betreff und Nachricht; KI-Bearbeitung und Diktat aus dem textbot-Package (nur wenn installiert) --}}
<div class="mb-2">
    <input type="text" class="form-control @error('subject') is-invalid @enderror" placeholder="Betreff"
        wire:model="subject">
    @error('subject')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-2" x-data="{ sig: false }">
    <textarea id="nfmailer-body" class="form-control @error('body') is-invalid @enderror" rows="12" placeholder="Nachricht …"
        wire:model="body" x-on:nfmailer-insert-transcript.window="nfmailerInsert(($event.detail.text ?? '').trim() + ' ')"></textarea>
    @error('body')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror

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

        @if (config('nfmailer.signature'))
            <button type="button" class="btn btn-link btn-sm text-decoration-none text-body-secondary ms-auto"
                x-on:click="sig = !sig" :aria-expanded="sig">
                Signatur <i class="bi" :class="sig ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
            </button>
        @endif
    </div>

    @if (config('nfmailer.signature'))
        <div class="form-text border-start ps-2 mt-1" style="white-space: pre-line" x-show="sig" x-cloak>– – –
            {{ config('nfmailer.signature') }}</div>
    @endif
</div>
