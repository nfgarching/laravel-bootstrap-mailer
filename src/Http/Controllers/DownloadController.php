<?php

namespace Nfgarching\Mailer\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Nfgarching\Mailer\Models\MailLink;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadController
{
    public function __invoke(string $token): StreamedResponse
    {
        $link = MailLink::where('token', $token)->first();

        abort_if($link === null, 404, 'Der Link ist ungültig.');
        abort_if($link->isExpired(), 410, 'Der Link ist abgelaufen. Bitte fordern Sie die Datei erneut an.');

        $disk = Storage::disk($link->disk);
        abort_unless($disk->exists($link->path), 404, 'Die Datei ist nicht mehr verfügbar.');

        $link->increment('download_count', 1, ['last_downloaded_at' => now()]);

        return $disk->download($link->path, $link->name);
    }
}
