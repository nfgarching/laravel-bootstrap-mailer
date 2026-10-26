<?php

use Illuminate\Support\Facades\Route;
use Nfgarching\Mailer\Http\Controllers\DownloadController;
use Nfgarching\Mailer\Livewire\MailComposer;

// Eigenständige Seite (Popup/Tab), vorbelegt über Query-Parameter wie die Optionen von 'nfmailer-open'
Route::middleware(['web', 'auth'])
    ->prefix(config('nfmailer.route_prefix', 'nfmailer'))
    ->get('compose', MailComposer::class)
    ->name('nfmailer.compose');

// Öffentlich: Empfänger haben kein Login. Schutz über 48-stelliges Zufallstoken
// (nur als Hash gespeichert), Ablaufdatum, optionale PIN und Rate-Limit.
Route::middleware('web')
    ->prefix(config('nfmailer.route_prefix', 'nfmailer'))
    ->group(function () {
        Route::get('d/{token}', [DownloadController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{48}')
            ->middleware('throttle:30,1')
            ->name('nfmailer.download');

        Route::post('d/{token}', [DownloadController::class, 'download'])
            ->where('token', '[A-Za-z0-9]{48}')
            ->middleware('throttle:10,1')
            ->name('nfmailer.download.file');
    });
