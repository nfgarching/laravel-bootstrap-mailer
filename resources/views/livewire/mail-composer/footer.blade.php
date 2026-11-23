{{-- Footer: Empfängerzahl, Status der Versandoptionen (Klick öffnet sie), Knöpfe --}}
@if ($result)
    <button type="button" class="btn btn-primary ms-auto" wire:click="closeResult">Schließen</button>
@else
    @php
        $total = count($to) + count($cc) + count($bcc);
        $hasAttachments = count(array_filter($attachments, fn($a) => $a['active'] ?? true)) || count($uploads);
    @endphp

    <div class="d-flex flex-wrap align-items-center gap-2 me-auto small">

        {{-- Status-Badges: Klick führt zu den Versandoptionen --}}
        <button type="button" class="btn btn-link p-0 text-decoration-none d-inline-flex gap-1"
            x-on:click="view = view === 'options' ? 'compose' : 'options'" title="Versandoptionen">
            @if ($sec['signs'])
                <span class="badge text-bg-light border"><i class="bi bi-patch-check text-success"></i> signiert</span>
            @endif
            @if ($sec['canEncrypt'] && $encrypt)
                @if ($sec['encryptPossible'])
                    <span class="badge text-bg-light border"><i class="bi bi-lock-fill text-success"></i>
                        verschlüsselt</span>
                @elseif ($sec['missing'])
                    <span class="badge text-bg-warning"><i class="bi bi-unlock"></i> nicht verschlüsselbar
                        ({{ count($sec['missing']) }})</span>
                @endif
            @endif
            @if ($asLinks && $hasAttachments)
                <span class="badge text-bg-light border"><i class="bi bi-link-45deg"></i>
                    {{ $this->portalAvailable ? 'Portal-Link' : 'Download-Link' }}{{ $usePin ? ($this->portalAvailable ? ' + Passwort' : ' + PIN') : '' }}</span>
            @endif
        </button>
    </div>

    <button type="button" class="btn btn-outline-secondary" x-show="view === 'compose'" x-on:click="view = 'options'"
        title="Versandoptionen: Download-Links, PIN, Verschlüsselung">
        <i class="bi bi-gear"></i><span class="d-none d-sm-inline ms-1">Optionen</span>
    </button>
    <button type="button" class="btn btn-outline-secondary" x-show="view === 'options'" x-cloak
        x-on:click="view = 'compose'">
        <i class="bi bi-arrow-left me-1"></i>Zur Nachricht
    </button>

    @if (config('nfmailer.drafts.enabled', true))
        <span class="small text-body-secondary text-nowrap" wire:loading.remove wire:target="saveDraft,autosave">
            @if ($this->dirty)
                <i class="bi bi-circle-fill text-warning" style="font-size: .5rem" title="Ungespeicherte Änderungen"></i>
            @elseif ($savedAt)
                <i class="bi bi-check2"></i> Entwurf {{ $savedAt }}
            @endif
        </span>
        <span class="small text-body-secondary text-nowrap" wire:loading wire:target="saveDraft,autosave">
            <span class="spinner-border spinner-border-sm"></span> speichert …
        </span>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-secondary" wire:click="saveDraft" wire:loading.attr="disabled"
                wire:target="saveDraft,send,newUploads" title="Als Entwurf speichern">
                <i class="bi bi-floppy"></i><span class="d-none d-md-inline ms-1">Entwurf</span>
            </button>
            @if ($draftId)
                <button type="button" class="btn btn-outline-danger" wire:click="discardDraft"
                    wire:confirm="Entwurf löschen?" title="Entwurf verwerfen">
                    <i class="bi bi-trash"></i>
                </button>
            @endif
        </div>
    @endif

    {{-- Schließen speichert ungesicherte Änderungen als Entwurf (Modal: hidden.bs.modal → autosave) --}}
    <button type="button" class="btn btn-outline-secondary"
        @if ($page) x-on:click="$wire.autosave().then(() => closePopup())" @else data-bs-dismiss="modal" @endif>
        Schließen
    </button>
    <button type="button" class="btn btn-outline-primary" wire:click="mailto"
        x-on:nfmailer-mailto.window="window.top.location.href = $event.detail.url"
        title="Im Standard-Mailprogramm öffnen (ohne Anhänge, ohne Protokoll)" wire:loading.attr="disabled"
        wire:target="mailto,send,newUploads">
        <i class="bi bi-box-arrow-up-right"></i><span class="d-none d-sm-inline ms-1">MailTo</span>
    </button>
    <button type="button" class="btn btn-primary" wire:click="send" wire:loading.attr="disabled"
        wire:target="send,newUploads">
        <span wire:loading wire:target="send" class="spinner-border spinner-border-sm me-1"></span>
        <i class="bi bi-send" wire:loading.remove wire:target="send"></i> Senden
    </button>
@endif
