<style>
    /* ---------------------------------------------- Rahmen */
    .nfm {
        --nfm-side-width: 26rem;
    }

    .nfm-page .nfm-dialog {
        max-width: 56rem;
    }

    /* Offcanvas offen: Dialog nach links rücken, damit er nicht verdeckt wird */
    @media (min-width: 1200px) {

        .nfm .nfm-dialog {
            transition: margin .2s ease-in-out;
        }

        .nfm.nfm-side-open .modal-dialog.nfm-dialog {
            margin-right: calc(var(--nfm-side-width) + 1.5rem);
        }

        .nfm-page.nfm-side-open .nfm-dialog {
            margin-right: calc(var(--nfm-side-width) + 1rem) !important;
        }
    }

    .nfm .nfm-offcanvas {
        --bs-offcanvas-width: var(--nfm-side-width);
    }

    /* ---------------------------------------------- Höhe an das Fenster anpassen
       Dialog bzw. Seite nimmt die volle Fensterhöhe ein, das Nachrichtenfeld füllt den
       verbleibenden Platz. Reicht er nicht (viele Empfänger/Anhänge), scrollt der modal-body. */
    .nfm .modal-dialog.nfm-dialog .modal-content {
        height: 100%;
    }

    .nfm-page .nfm-dialog {
        height: calc(100dvh - 2rem);
        overflow: hidden;
    }

    .nfm-page .modal-body {
        overflow-y: auto;
    }

    .nfm .modal-body {
        display: flex;
        flex-direction: column;
    }

    .nfm .nfm-compose,
    .nfm .nfm-body {
        flex: 1 0 auto;
        display: flex;
        flex-direction: column;
    }

    /* Schalter (Ablegen, Signatur) mit Rahmen in Höhe eines btn-sm */
    .nfm .nfm-switch {
        display: flex;
        align-items: center;
        min-height: calc(1.5em + .5rem + 2px);
        margin-bottom: 0;
        padding: .125rem .5rem .125rem 2.75em;
        border: var(--bs-border-width) solid var(--bs-border-color);
        border-radius: var(--bs-border-radius-sm);
        font-size: .875rem;
    }

    .nfm .nfm-switch .form-check-input {
        margin-top: 0;
    }

    .nfm .nfm-body-text {
        flex: 1 1 0;
        min-height: 12rem;
        resize: none;
    }

    /* ---------------------------------------------- Empfängerfelder */
    .nfm .nfm-field {
        min-height: 2.5rem;
        cursor: text;
        transition: border-color .15s, box-shadow .15s;
    }

    .nfm .nfm-active {
        border-color: var(--bs-primary) !important;
        box-shadow: 0 0 0 .2rem rgba(var(--bs-primary-rgb), .15);
    }

    .nfm .nfm-label {
        width: 2.4rem;
        flex-shrink: 0;
    }

    .nfm .nfm-book {
        padding: .1rem .4rem;
    }

    .nfm .nfm-free-wrap {
        min-width: 12rem;
    }

    .nfm .nfm-free {
        background: transparent;
    }

    .nfm .nfm-inline {
        top: 100%;
        left: 0;
        min-width: 20rem;
        max-height: 18rem;
        overflow-y: auto;
        z-index: 1060;
    }

    /* ---------------------------------------------- Chips (Empfänger, Anhänge) */
    .nfm .nfm-chip {
        background: var(--bs-secondary-bg);
        color: var(--bs-body-color);
        border: 1px solid var(--bs-border-color);
        font-weight: 500;
        font-size: .85rem;
        padding: .3rem .4rem .3rem .65rem;
        max-width: 100%;
    }

    .nfm .nfm-chip-text {
        font-size: inherit;
        font-weight: inherit;
        max-width: 18rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .nfm .nfm-chip-x {
        font-size: .55rem;
        padding: .25rem;
    }

    /* ---------------------------------------------- Listen in den Offcanvas */
    .nfm .nfm-presets {
        max-height: 14rem;
    }

    .nfm .nfm-taken {
        background: rgba(var(--bs-primary-rgb), .05);
    }

    .nfm .nfm-quick {
        opacity: 0;
        transition: opacity .1s;
    }

    .nfm .list-group-item:hover .nfm-quick,
    .nfm .nfm-quick:focus-within {
        opacity: 1;
    }

    @media (hover: none) {
        .nfm .nfm-quick {
            opacity: 1;
        }
    }

    .nfm .min-w-0 {
        min-width: 0;
    }

    [x-cloak] {
        display: none !important;
    }

    /* Diktat-Button (textbot-Partial) während der Aufnahme */
    .nfm .textbot-mic-pulse i {
        animation: nfm-mic-pulse 1.2s ease-in-out infinite;
    }

    @keyframes nfm-mic-pulse {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: .35;
        }
    }
</style>

<script>
    // Text am Cursor in die Nachricht einfügen (Diktat) und Livewire syncen.
    function nfmailerInsert(newText) {
        const input = document.getElementById('nfmailer-body');
        if (!input) return;
        input.setRangeText(newText, input.selectionStart, input.selectionEnd, 'end');
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus();
    }
</script>
