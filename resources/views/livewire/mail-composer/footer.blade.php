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
                    Download-Link{{ $usePin ? ' + PIN' : '' }}</span>
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

    <button type="button" class="btn btn-outline-secondary"
        @if ($page) onclick="window.close()" @else data-bs-dismiss="modal" @endif>
        Abbrechen
    </button>
    <button type="button" class="btn btn-outline-primary" wire:click="mailto"
        x-on:nfmailer-mailto.window="window.location.href = $event.detail.url"
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
