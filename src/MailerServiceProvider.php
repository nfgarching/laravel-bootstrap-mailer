<?php

namespace Nfgarching\Mailer;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Nfgarching\Mailer\Livewire\MailComposer;
use Nfgarching\Mailer\Sources\RecipientResolver;

class MailerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/nfmailer.php', 'nfmailer');

        $this->app->scoped(RecipientResolver::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'nfmailer');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        Livewire::component('nf-mail-composer', MailComposer::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/nfmailer.php' => config_path('nfmailer.php'),
            ], 'nfmailer-config');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/nfmailer'),
            ], 'nfmailer-views');

            $this->publishes([
                __DIR__ . '/../stubs/AkteBeteiligteSource.php' => app_path('Mail/Recipients/AkteBeteiligteSource.php'),
            ], 'nfmailer-stubs');
        }
    }
}
