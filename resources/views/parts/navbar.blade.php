{{-- Navbar-Button: E-Mail verfassen (nf-mail-composer, eingebunden über parts.modals) --}}
@auth
    <li class="nav-item d-none d-lg-inline">
        <button type="button" class="nav-link" data-bs-toggle="tooltip" data-bs-title="E-Mail verfassen"
            onclick="Livewire.dispatch('nfmailer-open', { options: {} })">
            <i class="bi bi-envelope-plus"></i>
        </button>
    </li>
@endauth
