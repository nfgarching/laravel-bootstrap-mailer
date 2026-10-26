<?php

namespace Nfgarching\Mailer;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Livewire\Livewire;
use Nfgarching\Core\Modals\ModalRegistry;
use Nfgarching\Core\Navigation\NavbarNavigation;
use Nfgarching\Mailer\Console\CertCommand;
use Nfgarching\Mailer\Console\CheckCommand;
use Nfgarching\Mailer\Console\PruneCommand;
use Nfgarching\Mailer\Livewire\MailComposer;
use Nfgarching\Mailer\Security\CertificateStore;
use Nfgarching\Mailer\Security\SecureTransport;
use Nfgarching\Mailer\Sources\RecipientResolver;

class MailerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailer.php', 'nfmailer');

        $this->app->scoped(RecipientResolver::class);
        $this->app->singleton(CertificateStore::class, fn () => CertificateStore::fromConfig());

        // Mailer „nfmailer“: umhüllt den eigentlichen Versandweg (TLS-Pflicht, S/MIME)
        if (! $this->app['config']->has('mail.mailers.nfmailer')) {
            $this->app['config']->set('mail.mailers.nfmailer', ['transport' => 'nfmailer']);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nfmailer');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Livewire::component('nf-mail-composer', MailComposer::class);

        Mail::extend('nfmailer', function (array $config) {
            $inner = $config['inner'] ?? config('nfmailer.mailer') ?: config('mail.default');

            if ($inner === 'nfmailer') {
                throw new InvalidArgumentException('NFMAILER_MAILER bzw. MAIL_MAILER darf nicht „nfmailer“ sein.');
            }

            $innerConfig = config("mail.mailers.{$inner}");

            if (! is_array($innerConfig)) {
                throw new InvalidArgumentException("Mailer [{$inner}] ist in config/mail.php nicht definiert.");
            }

            // Eigene Transport-Instanz, damit die TLS-Pflicht nur für diesen Weg gilt
            return new SecureTransport(
                Mail::createSymfonyTransport($innerConfig),
                (array) config('nfmailer.smime', []),
                $this->app->make(CertificateStore::class),
                (bool) config('nfmailer.require_tls', true),
            );
        });

        // Navbar-Button und Modal nur, wenn das core-Package die Registries bereitstellt
        if (class_exists(NavbarNavigation::class)) {
            $this->app->make(NavbarNavigation::class)->item('nfmailer::parts.navbar', order: 72);
            $this->app->make(ModalRegistry::class)->modal('nfmailer::parts.modals', order: 85);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CertCommand::class, CheckCommand::class, PruneCommand::class]);

            $this->publishes([
                __DIR__.'/../config/mailer.php' => config_path('nfmailer.php'),
            ], 'nfmailer-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/nfmailer'),
            ], 'nfmailer-views');

            $this->publishes([
                __DIR__.'/../stubs/AkteBeteiligteSource.php' => app_path('Mail/Recipients/AkteBeteiligteSource.php'),
            ], 'nfmailer-stubs');
        }
    }
}
