<?php

namespace Nfgarching\Mailer\Events;

use Illuminate\Mail\SentMessage;
use Nfgarching\Mailer\Models\SentMail;

/**
 * Eine im Modal verfasste Mail ist verschickt (direkt oder durch den Queue-Worker).
 *
 * Die Anwendung kann sie hier ablegen, wenn $log->archive gesetzt ist; der Kontext
 * (z. B. ['akte' => 123, 'dokument' => 456]) steht in $log->context.
 *
 * eml() liefert die Mail so, wie sie verfasst wurde: unverschlüsselt und ohne
 * S/MIME-Signatur (SecureTransport gibt die ursprüngliche Email zurück), ohne Bcc-Header,
 * mit der Message-ID der versandten Mail.
 */
class MailSent
{
    public function __construct(
        public SentMail $log,
        public SentMessage $sent,
    ) {}

    public function eml(): string
    {
        // Nicht getOriginalMessage(): Fehlt dort der Message-ID-Header, erzeugt
        // toString() bei jedem Aufruf eine neue ID.
        return $this->sent->getSymfonySentMessage()->toString();
    }
}
