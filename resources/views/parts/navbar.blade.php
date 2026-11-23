{{-- Navbar-Button: E-Mail verfassen (Seite nfmailer.compose).
     Klick => iframe-Modal (xl), SHIFT => Popup, CTRL => Tab.
     Daneben: Entwürfe mit Anzahl der eigenen (bzw. bei drafts.shared aller) Entwürfe. --}}
@auth
    <li class="nav-item d-none d-lg-inline">
        <x-nav.popup frame frameSize="xl" :href="route('nfmailer.compose')" popup="width=1200,height=900"
            tooltip="E-Mail verfassen">
            <i class="bi bi-envelope-plus"></i>
        </x-nav.popup>
    </li>
    @if (config('nfmailer.drafts.enabled', true))
        @php
            $nfmDrafts = \Nfgarching\Mailer\Models\SentMail::drafts()->visibleTo(auth()->id())->count();
        @endphp
        <li class="nav-item d-none d-lg-inline">
            <a class="nav-link position-relative" href="{{ route('nfmailer.drafts') }}" title="E-Mail-Entwürfe">
                <i class="bi bi-envelope-paper"></i>
                @if ($nfmDrafts)
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-secondary"
                        style="font-size: .6rem">{{ $nfmDrafts }}</span>
                @endif
            </a>
        </li>
    @endif
@endauth
