{{-- Bestätigung nach Versand mit PIN-geschützten Download-Links --}}
<div class="text-center py-4 mx-auto" style="max-width: 30rem">
    <i class="bi bi-check-circle text-success display-5"></i>
    <h5 class="mt-2">E-Mail versandt</h5>
    <p class="text-body-secondary small mb-3">
        @if ($result['signed'])
            <span class="badge text-bg-light border"><i class="bi bi-patch-check"></i> signiert</span>
        @endif
        @if ($result['encrypted'])
            <span class="badge text-bg-light border"><i class="bi bi-lock-fill"></i> verschlüsselt</span>
        @endif
    </p>
    <div class="border rounded p-3 bg-body-tertiary">
        <div class="small text-body-secondary">PIN für die Download-Links</div>
        <div class="fs-2 fw-bold font-monospace user-select-all" style="letter-spacing: .3rem">
            {{ $result['pin'] }}</div>
        <div class="small mt-1">
            @if ($result['pinMailed'])
                Die PIN wurde zusätzlich in einer separaten E-Mail versandt.
            @else
                Bitte teilen Sie die PIN dem Empfänger telefonisch oder per SMS mit.
            @endif
        </div>
    </div>
    <p class="small text-body-secondary mt-3 mb-0">Die PIN bleibt im Versandprotokoll (verschlüsselt) abrufbar.</p>
</div>
