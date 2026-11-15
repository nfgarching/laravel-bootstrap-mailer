{{-- Navbar-Button: E-Mail verfassen (Seite nfmailer.compose).
     Klick => iframe-Modal (xl), SHIFT => Popup, CTRL => Tab. --}}
@auth
    <li class="nav-item d-none d-lg-inline">
        <x-nav.popup frame frameSize="xl" :href="route('nfmailer.compose')" popup="width=1200,height=900"
            tooltip="E-Mail verfassen">
            <i class="bi bi-envelope-plus"></i>
        </x-nav.popup>
    </li>
@endauth
