<?php

namespace Nfgarching\Mailer\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Nfgarching\Mailer\Events\MailSent;
use Nfgarching\Mailer\Mail\ComposedMail;
use Nfgarching\Mailer\Models\SentMail;
use Throwable;

/**
 * Nach dem Versand einer im Modal verfassten Mail (direkt oder durch den Queue-Worker):
 *
 *  1. Protokoll „pending“/„queued“ → „sent“ mit Zeitpunkt und Message-ID
 *  2. Ereignis MailSent auslösen (Ablage durch die Anwendung, siehe $log->archive)
 *
 * Fehler in Schritt 2 werden nur gemeldet: Die Mail ist bereits verschickt und darf
 * weder als gescheitert gelten noch (beim Queue-Versand) erneut verschickt werden.
 */
class RecordSentMail
{
    public function handle(MessageSent $event): void
    {
        if (($event->data['__laravel_mailable'] ?? null) !== ComposedMail::class) {
            return;
        }

        $log = ($id = $event->data['sentMailId'] ?? null) ? SentMail::find($id) : null;

        if (! $log) {
            return;
        }

        if (in_array($log->status, ['pending', 'queued'], true)) {
            $log->update([
                'status' => 'sent',
                'sent_at' => now(),
                'message_id' => $event->sent->getMessageId(),
                'error' => null,
            ]);
        }

        try {
            event(new MailSent($log, $event->sent));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
