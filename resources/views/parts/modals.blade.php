{{-- Über die ModalRegistry in jedes Layout eingebunden (MailerServiceProvider).
     Nicht auf der eigenständigen Seite nfmailer.compose: dort ist die Seite selbst der Composer. --}}
@auth
    @unless (request()->routeIs('nfmailer.compose'))
        <livewire:nf-mail-composer />
    @endunless
@endauth
