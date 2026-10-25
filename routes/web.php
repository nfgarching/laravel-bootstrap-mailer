<?php

use Illuminate\Support\Facades\Route;
use Nfgarching\Mailer\Http\Controllers\DownloadController;

// Öffentlich: Empfänger haben kein Login. Schutz über 48-stelliges Zufallstoken + Ablaufdatum.
Route::middleware(['web', 'throttle:30,1'])
    ->prefix(config('nfmailer.route_prefix', 'nfmailer'))
    ->get('d/{token}', DownloadController::class)
    ->where('token', '[A-Za-z0-9]{48}')
    ->name('nfmailer.download');
