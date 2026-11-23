<?php

namespace Nfgarching\Mailer\Support;

use Nfgarching\Mailer\Security\CertificateStore;

/**
 * Stand von S/MIME-Signatur und -Verschlüsselung für eine Empfängerliste.
 * Gemeinsam genutzt vom Modal (Anzeige) und vom MailDispatcher (Versand).
 */
class SecurityStatus
{
    /**
     * @param  list<array{email: string}|string>  $recipients
     * @return array{signs: bool, canEncrypt: bool, encryptPossible: bool, missing: list<string>, certs: array<string, bool>}
     */
    public static function for(array $recipients): array
    {
        $smime = (array) config('nfmailer.smime', []);
        $signs = ! empty($smime['certificate']) && ! empty($smime['key']);
        $canEncrypt = (bool) ($smime['encrypt'] ?? false);

        $emails = array_values(array_unique(array_map(
            fn ($r) => mb_strtolower(is_array($r) ? $r['email'] : $r),
            $recipients,
        )));

        $certs = [];
        $missing = [];

        if ($canEncrypt) {
            $store = app(CertificateStore::class);

            foreach ($emails as $e) {
                $certs[$e] = $store->has($e);

                if (! $certs[$e]) {
                    $missing[] = $e.' ('.$store->unusableReason($e).')';
                }
            }
        }

        return [
            'signs' => $signs,
            'canEncrypt' => $canEncrypt,
            'encryptPossible' => $canEncrypt && $emails && ! $missing,
            'missing' => $missing,
            'certs' => $certs,
        ];
    }
}
