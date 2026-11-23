<?php

namespace Nfgarching\Mailer\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Nfgarching\Mailer\Models\SentMail;

/**
 * Wer darf Entwürfe und Protokolleinträge sehen, bearbeiten, senden, löschen?
 *
 *  - Standard: nur der Verfasser (user_id)
 *  - nfmailer.drafts.shared = true: alle angemeldeten User (z. B. Sekretariat)
 *  - Senden zusätzlich über den Gate-Namen nfmailer.gate, falls gesetzt
 *
 * Eigene Regeln (z. B. Zugriff auf die Akte) über Gate::policy(SentMail::class, …)
 * in der Anwendung, nach dem MailerServiceProvider registriert.
 */
class SentMailPolicy
{
    public function view(Authenticatable $user, SentMail $mail): bool
    {
        return $this->owns($user, $mail) || config('nfmailer.drafts.shared', false);
    }

    public function update(Authenticatable $user, SentMail $mail): bool
    {
        return $mail->isDraft() && $this->view($user, $mail);
    }

    public function delete(Authenticatable $user, SentMail $mail): bool
    {
        return $this->update($user, $mail);
    }

    /** Senden überhaupt (ohne Datensatz) */
    public function create(Authenticatable $user): bool
    {
        $gate = config('nfmailer.gate');

        return ! $gate || Gate::forUser($user)->allows($gate);
    }

    /** Einen Entwurf versenden */
    public function send(Authenticatable $user, SentMail $mail): bool
    {
        return $this->update($user, $mail) && $this->create($user);
    }

    protected function owns(Authenticatable $user, SentMail $mail): bool
    {
        return $mail->user_id !== null && (string) $mail->user_id === (string) $user->getAuthIdentifier();
    }
}
