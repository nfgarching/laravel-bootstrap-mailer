{{--
    E-Mail verfassen – Hülle für Modal (über die ModalRegistry in jedes Layout) und
    eigenständige Seite (Route nfmailer.compose, Popup oder Tab).

    Aufbau (Partials unter mail-composer/):
      content      Kopf, Inhalt (Nachricht | Versandoptionen | Ergebnis) und Footer
      recipients   An/CC/BCC mit Chips und Inline-Vorschlägen
      body         Betreff, Nachricht, KI/Diktat, Signatur
      attachments  Anhang-Chips, Upload, Knopf zum Offcanvas „Anhänge“
      options      Versandoptionen (Download-Links, PIN, S/MIME) – Ansicht im selben Modal,
                   weil Bootstrap keine Modals im Modal unterstützt
      result       Bestätigung nach Versand mit PIN
      footer       Empfängerzahl, Status-Badges, Knöpfe
      addressbook  Offcanvas: Suche, Vorschläge, Favoriten, Mehrfachauswahl
      sources      Offcanvas: übergebene Anhänge und Dokumente der Akte
      assets       CSS und JS

    Die Offcanvas liegen im DOM innerhalb von #nfmailerModal: ohne Backdrop und mit
    scroll=true aktivieren sie keine eigene Fokusfalle, die des Modals bleibt intakt und
    lässt den Fokus in die Offcanvas. Alpine-Zustand: view (compose|options), side (Offcanvas offen).
--}}
<div>
    @if ($page)
        {{-- Eigenständige Seite: nach Versand bzw. Übergabe ans Mailprogramm schließt sich das Fenster --}}
        <div class="nfm nfm-page py-3 px-2" id="nfmailerPage" x-data="{ view: 'compose', side: false }"
            :class="{ 'nfm-side-open': side }" x-init="$el.querySelector('.nfm-free')?.focus()"
            x-on:nfmailer-hide.window="setTimeout(() => window.close(), 500)"
            x-on:show-bs-offcanvas.dot="side = true"
            x-on:hidden-bs-offcanvas.dot="side = !!$root.querySelector('.offcanvas.show')">
            <div class="modal-content border rounded nfm-dialog mx-auto">
                @include('nfmailer::livewire.mail-composer.content')
            </div>

            @include('nfmailer::livewire.mail-composer.addressbook')
            @include('nfmailer::livewire.mail-composer.sources')
        </div>
    @else
        <div class="modal fade nfm" id="nfmailerModal" tabindex="-1" aria-labelledby="nfmailerTitle"
            aria-hidden="true" wire:ignore.self x-data="{ view: 'compose', side: false }"
            :class="{ 'nfm-side-open': side }"
            x-on:nfmailer-show.window="view = 'compose'; bootstrap.Modal.getOrCreateInstance($el).show()"
            x-on:nfmailer-hide.window="bootstrap.Modal.getOrCreateInstance($el).hide()"
            x-on:shown-bs-modal.dot="$el.querySelector('.nfm-free')?.focus()"
            x-on:hide-bs-modal.dot="$el.querySelectorAll('.offcanvas.show').forEach(o => bootstrap.Offcanvas.getInstance(o)?.hide())"
            x-on:show-bs-offcanvas.dot="side = true"
            x-on:hidden-bs-offcanvas.dot="side = !!$root.querySelector('.offcanvas.show')">
            <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-md-down nfm-dialog">
                <div class="modal-content">
                    @include('nfmailer::livewire.mail-composer.content')
                </div>
            </div>

            @include('nfmailer::livewire.mail-composer.addressbook')
            @include('nfmailer::livewire.mail-composer.sources')
        </div>
    @endif

    @include('nfmailer::livewire.mail-composer.assets')
</div>
