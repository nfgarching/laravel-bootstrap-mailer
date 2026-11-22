<button type="button" class="btn btn-sm {{ $preview ? 'btn-secondary' : 'btn-outline-secondary' }}"
    wire:click="$toggle('preview')"
    title="{{ $preview ? 'Zurück zum Bearbeiten' : 'Nachricht so anzeigen, wie sie versendet wird' }}">
    <i class="bi {{ $preview ? 'bi-pencil' : 'bi-eye' }} me-1"></i>{{ $preview ? 'Bearbeiten' : 'Vorschau' }}
</button>
