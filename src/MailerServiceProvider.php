<?php

namespace Nfgarching\Mailer;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nfgarching\Core\Modals\ModalRegistry;
use Nfgarching\Core\Navigation\NavbarNavigation;
use Nfgarching\Mailer\Livewire\MailComposer;
use Nfgarching\Mailer\Sources\RecipientResolver;

class MailerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mailer.php', 'nfmailer');

        $this->app->scoped(RecipientResolver::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nfmailer');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        Livewire::component('nf-mail-composer', MailComposer::class);

        // Navbar-Button und Modal nur, wenn das core-Package die Registries bereitstellt
        if (class_exists(NavbarNavigation::class)) {
            $this->app->make(NavbarNavigation::class)->item('nfmailer::parts.navbar', order: 72);
            $this->app->make(ModalRegistry::class)->modal('nfmailer::parts.modals', order: 85);
        }

        if ($this->app->runningInConsole()) {
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
