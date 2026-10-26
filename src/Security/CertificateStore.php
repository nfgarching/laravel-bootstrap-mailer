<?php

namespace Nfgarching\Mailer\Security;

use RuntimeException;

/**
 * Ablage der S/MIME-Zertifikate von Empfängern (öffentliche Schlüssel).
 *
 * Je Adresse eine PEM-Datei im konfigurierten Verzeichnis. Zertifikate sind
 * nicht geheim; geheim ist nur der eigene private Schlüssel (siehe Config smime.key).
 */
class CertificateStore
{
    public function __construct(protected string $directory) {}

    public static function fromConfig(): self
    {
        return new self((string) config('nfmailer.smime.certificates', storage_path('app/private/nfmailer/certs')));
    }

    /** Pfad zum gültigen (nicht abgelaufenen) Zertifikat oder null. */
    public function path(string $email): ?string
    {
        $file = $this->file($email);

        if (! is_file($file)) {
            return null;
        }

        $info = $this->inspect((string) file_get_contents($file));

        return $info && ! $info['expired'] ? $file : null;
    }

    public function has(string $email): bool
    {
        return $this->path($email) !== null;
    }

    /**
     * @param  list<string>  $emails
     * @return list<string>  Adressen ohne gültiges Zertifikat
     */
    public function missing(array $emails): array
    {
        return array_values(array_filter(array_unique(array_map('mb_strtolower', $emails)), fn ($e) => ! $this->has($e)));
    }

    /**
     * Importiert Empfänger-Zertifikate aus
     *  - .cer/.crt/.pem (PEM oder DER, auch mehrere Zertifikate/ganze Kette),
     *  - .p7s/.p7b/.p7c (PKCS#7, PEM oder DER),
     *  - .eml: gespeicherte, S/MIME-signierte E-Mail (Signatur wird geprüft).
     *
     * Übernommen werden nur Zertifikate mit E-Mail-Adresse (also keine CA-Zertifikate),
     * jeweils unter jeder enthaltenen Adresse.
     *
     * @return list<array{emails: list<string>, subject: string, issuer: string, valid_to: string, fingerprint: string}>
     */
    public function import(string $contents, ?string $onlyEmail = null): array
    {
        $candidates = [];
        $expired = [];

        foreach (self::extract($contents) as $pem) {
            $info = $this->inspect($pem);
            if (! $info || ! $info['emails']) {
                continue;   // CA-Zertifikat o. Ä.
            }

            $emails = $onlyEmail !== null
                ? array_values(array_intersect($info['emails'], [mb_strtolower(trim($onlyEmail))]))
                : $info['emails'];

            if (! $emails) {
                continue;
            }

            if ($info['expired']) {
                $expired[] = implode(', ', $emails) . ' (abgelaufen am ' . $info['valid_to'] . ')';

                continue;
            }

            $candidates[$info['fingerprint']] = ['pem' => $pem, 'emails' => $emails] + $info;
        }

        if (! $candidates) {
            throw new RuntimeException(match (true) {
                (bool) $expired     => 'Nur abgelaufene Zertifikate gefunden: ' . implode('; ', $expired),
                $onlyEmail !== null => "Kein Zertifikat für {$onlyEmail} in der Datei.",
                default             => 'Kein Zertifikat mit E-Mail-Adresse in der Datei gefunden.',
            });
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Verzeichnis kann nicht angelegt werden: ' . $this->directory);
        }

        $result = [];
        foreach ($candidates as $c) {
            foreach ($c['emails'] as $email) {
                file_put_contents($this->file($email), $c['pem']);
            }
            $result[] = array_intersect_key($c, array_flip(['emails', 'subject', 'issuer', 'valid_to', 'fingerprint']));
        }

        return $result;
    }

    /** Details zum hinterlegten Zertifikat einer Adresse (auch abgelaufen) oder null */
    public function show(string $email): ?array
    {
        $file = $this->file($email);

        return is_file($file) ? $this->inspect((string) file_get_contents($file)) : null;
    }

    /**
     * Holt alle Zertifikate (PEM) aus einer Datei beliebigen Formats.
     *
     * @return list<string>
     */
    public static function extract(string $contents): array
    {
        // Gespeicherte E-Mail (MIME) mit S/MIME-Signatur
        if (preg_match('/^content-type:\s*(multipart\/signed|application\/(x-)?pkcs7-mime)/im', $contents)) {
            return self::fromSignedMail($contents);
        }

        // PEM-Zertifikate (eins oder mehrere)
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $m)) {
            return array_map(fn ($c) => $c . "\n", $m[0]);
        }

        // PKCS#7 als PEM
        if (str_contains($contents, '-----BEGIN PKCS7-----')) {
            return self::fromPkcs7Pem($contents);
        }

        // Binär (DER) oder Base64 ohne Kopfzeilen
        $decoded = base64_decode(preg_replace('/\s+/', '', $contents), true);
        foreach (array_filter([$contents, $decoded !== false ? $decoded : null]) as $der) {
            if (@openssl_x509_parse($pem = self::wrap($der, 'CERTIFICATE'))) {
                return [$pem];
            }
            if ($certs = self::fromPkcs7Pem(self::wrap($der, 'PKCS7'))) {
                return $certs;
            }
        }

        return [];
    }

    /** @return list<string> */
    protected static function fromPkcs7Pem(string $pem): array
    {
        $certs = [];

        return @openssl_pkcs7_read($pem, $certs) ? array_values($certs) : [];
    }

    /**
     * Signaturzertifikat(e) aus einer signierten Mail. Die Signatur wird dabei geprüft
     * (ohne Vertrauenskette): Wurde die Mail verändert, wird nichts übernommen.
     *
     * @return list<string>
     */
    protected static function fromSignedMail(string $mail): array
    {
        $in = tempnam(sys_get_temp_dir(), 'nfm');
        $out = tempnam(sys_get_temp_dir(), 'nfm');

        try {
            // Zeilenenden vereinheitlichen (gespeicherte .eml haben mal LF, mal CRLF)
            file_put_contents($in, preg_replace("/\r?\n/", "\r\n", $mail));

            $ok = openssl_pkcs7_verify($in, PKCS7_NOVERIFY, $out);

            if ($ok !== true) {
                throw new RuntimeException($ok === false
                    ? 'Die Signatur der E-Mail ist ungültig (Mail verändert?). Zertifikat nicht übernommen.'
                    : 'Die E-Mail enthält keine lesbare S/MIME-Signatur: ' . openssl_error_string());
            }

            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($out), $m);

            return array_map(fn ($c) => $c . "\n", $m[0]);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    public function remove(string $email): bool
    {
        $file = $this->file($email);

        return is_file($file) && unlink($file);
    }

    /** @return list<array{email: string, subject: string, valid_to: string, expired: bool}> */
    public function all(): array
    {
        $out = [];

        foreach (glob(rtrim($this->directory, '/') . '/*.pem') ?: [] as $file) {
            if ($info = $this->inspect((string) file_get_contents($file))) {
                $out[] = ['email' => basename($file, '.pem')] + $info;
            }
        }

        return $out;
    }

    /**
     * @return array{emails: list<string>, subject: string, issuer: string, fingerprint: string, valid_to: string, valid_to_ts: int, expired: bool}|null
     */
    public function inspect(string $pem): ?array
    {
        $data = @openssl_x509_parse($pem);

        if (! $data) {
            return null;
        }

        $emails = [];

        if (! empty($data['subject']['emailAddress'])) {
            foreach ((array) $data['subject']['emailAddress'] as $e) {
                $emails[] = $e;
            }
        }

        foreach (explode(',', (string) ($data['extensions']['subjectAltName'] ?? '')) as $part) {
            $part = trim($part);
            if (str_starts_with(strtolower($part), 'email:')) {
                $emails[] = substr($part, 6);
            }
        }

        $subject = $data['subject']['CN'] ?? ($data['name'] ?? '');
        $issuer = $data['issuer']['O'] ?? $data['issuer']['CN'] ?? '';
        $fingerprint = strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($pem, 'sha256'), 2)));

        return [
            'emails'   => array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $emails))),
            'subject'  => is_array($subject) ? implode(', ', $subject) : (string) $subject,
            'issuer'   => is_array($issuer) ? implode(', ', $issuer) : (string) $issuer,
            'fingerprint' => $fingerprint,
            'valid_to' => date('d.m.Y', (int) $data['validTo_time_t']),
            'valid_to_ts' => (int) $data['validTo_time_t'],
            'expired'  => (int) $data['validTo_time_t'] < time(),
        ];
    }

    protected static function wrap(string $der, string $type): string
    {
        return "-----BEGIN {$type}-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END {$type}-----\n";
    }

    protected function file(string $email): string
    {
        $name = preg_replace('/[^a-z0-9@._+-]/', '_', mb_strtolower(trim($email)));

        return rtrim($this->directory, '/') . '/' . $name . '.pem';
    }
}
