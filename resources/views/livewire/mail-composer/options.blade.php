{{-- Versandoptionen: Ansicht im Composer (Alpine view = 'options'), kein eigenes Modal --}}
@php
        $total = count($to) + count($cc) + count($bcc);
    $hasAttachments = count(array_filter($attachments, fn ($a) => $a['active'] ?? true)) || count($uploads);
@endphp

<div class="mx-auto" style="max-width: 40rem">

    {{-- Anhänge als Download-Link --}}
    <h6 class="text-body-secondary text-uppercase small fw-semibold mb-2"><i class="bi bi-link-45deg"></i> Anhänge</h6>
    <div class="border rounded p-3 mb-4">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="nfmAsLinks" wire:model.live="asLinks">
            <label class="form-check-label" for="nfmAsLinks">
                Als Download-Link statt Anhang senden
                @if (config('nfmailer.links.expires_days'))
                    <span class="text-body-secondary small">(gültig {{ config('nfmailer.links.expires_days') }} Tage)</span>
                @endif
            </label>
        </div>
        @unless ($hasAttachments)
            <div class="small text-body-secondary ms-5">Wirkt nur, wenn Anhänge vorhanden sind.</div>
        @endunless

        @if ($asLinks)
            <div class="ms-4 mt-2">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="nfmUsePin"
                        wire:model.live="usePin">
                    <label class="form-check-label" for="nfmUsePin">Download mit PIN schützen</label>
                </div>
                @if ($usePin)
                    <div class="form-check mb-0 mt-1">
                        <input class="form-check-input" type="checkbox" id="nfmPinByMail" wire:model.live="pinByMail">
                        <label class="form-check-label" for="nfmPinByMail">
                            PIN zusätzlich per separater E-Mail senden
                            <span class="text-body-secondary small">(sonst telefonisch/SMS mitteilen)</span>
                        </label>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- S/MIME --}}
    @if ($sec['signs'] || $sec['canEncrypt'])
        <h6 class="text-body-secondary text-uppercase small fw-semibold mb-2"><i class="bi bi-shield-lock"></i> Sicherheit
            (S/MIME)</h6>
        <div class="border rounded p-3">
            @if ($sec['signs'])
                <div class="mb-2" title="Die E-Mail wird mit dem S/MIME-Zertifikat der Kanzlei signiert">
                    <i class="bi bi-patch-check text-success"></i> Wird signiert
                </div>
            @endif

            @if ($sec['canEncrypt'])
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="nfmEncrypt"
                        wire:model.live="encrypt" @disabled(!$sec['encryptPossible'])>
                    <label class="form-check-label" for="nfmEncrypt">
                        <i class="bi {{ $encrypt && $sec['encryptPossible'] ? 'bi-lock-fill text-success' : 'bi-unlock' }}"></i>
                        Verschlüsseln
                    </label>
                </div>

                @if ($sec['missing'])
                    <div class="alert alert-warning small py-2 mt-2 mb-0">
                        Verschlüsselung nicht möglich für: {{ implode(', ', $sec['missing']) }}
                        @if ($sec['canLookup'])
                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary bg-body"
                                    wire:click="lookupCertificates" wire:loading.attr="disabled"
                                    wire:target="lookupCertificates"
                                    title="Sucht in den öffentlichen Verzeichnissen der Zertifizierungsstellen (D-Trust, DFN, TeleSec, SwissSign). Die Adressen werden dabei an diese Verzeichnisse übermittelt.">
                                    <span wire:loading.remove wire:target="lookupCertificates"><i
                                            class="bi bi-search"></i></span>
                                    <span wire:loading wire:target="lookupCertificates"
                                        class="spinner-border spinner-border-sm"></span>
                                    Zertifikate in öffentlichen Verzeichnissen suchen
                                </button>
                            </div>
                        @endif
                    </div>
                @elseif ($encrypt && $sec['encryptPossible'])
                    <div class="small text-body-secondary mt-1">Hinweis: Der Betreff wird nicht verschlüsselt.</div>
                @endif

                @if ($lookupResults)
                    <ul class="list-unstyled small text-body-secondary mt-2 mb-0">
                        @foreach ($lookupResults as $line)
                            <li wire:key="lookup-{{ $loop->index }}"><i class="bi bi-search"></i> {{ $line }}</li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    @endif

        <span class="text-body-secondary">
            {{ $total }} Empfänger
            @if (count($cc) || count($bcc))
                ({{ count($to) }} An · {{ count($cc) }} CC · {{ count($bcc) }} BCC)
            @endif
        </span>


</div>
