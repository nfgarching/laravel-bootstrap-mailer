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
     * Importiert ein Zertifikat (PEM oder DER, z. B. aus Outlook exportierte .cer).
     * Gespeichert wird es unter jeder darin enthaltenen E-Mail-Adresse.
     *
     * @return array{emails: list<string>, subject: string, valid_to: string}
     */
    public function import(string $contents, ?string $onlyEmail = null): array
    {
        $pem = self::toPem($contents);
        $info = $this->inspect($pem);

        if (! $info) {
            throw new RuntimeException('Die Datei enthält kein lesbares X.509-Zertifikat.');
        }
        if ($info['expired']) {
            throw new RuntimeException('Das Zertifikat ist am ' . $info['valid_to'] . ' abgelaufen.');
        }

        $emails = $info['emails'];
        if ($onlyEmail !== null) {
            $emails = array_values(array_intersect($emails, [mb_strtolower($onlyEmail)]));
        }
        if (! $emails) {
            throw new RuntimeException('Das Zertifikat enthält keine (passende) E-Mail-Adresse.');
        }

        if (! is_dir($this->directory) && ! mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Verzeichnis kann nicht angelegt werden: ' . $this->directory);
        }

        foreach ($emails as $email) {
            file_put_contents($this->file($email), $pem);
        }

        return ['emails' => $emails, 'subject' => $info['subject'], 'valid_to' => $info['valid_to']];
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
     * @return array{emails: list<string>, subject: string, valid_to: string, valid_to_ts: int, expired: bool}|null
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

        return [
            'emails'   => array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $emails))),
            'subject'  => is_array($subject) ? implode(', ', $subject) : (string) $subject,
            'valid_to' => date('d.m.Y', (int) $data['validTo_time_t']),
            'valid_to_ts' => (int) $data['validTo_time_t'],
            'expired'  => (int) $data['validTo_time_t'] < time(),
        ];
    }

    public static function toPem(string $contents): string
    {
        if (str_contains($contents, '-----BEGIN CERTIFICATE-----')) {
            preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $m);

            return $m[0] . "\n";
        }

        // DER (binär) oder Base64 ohne Kopfzeilen
        $der = base64_decode(preg_replace('/\s+/', '', $contents), true);
        $der = ($der !== false && @openssl_x509_parse(self::wrap($der))) ? $der : $contents;

        return self::wrap($der);
    }

    protected static function wrap(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    protected function file(string $email): string
    {
        $name = preg_replace('/[^a-z0-9@._+-]/', '_', mb_strtolower(trim($email)));

        return rtrim($this->directory, '/') . '/' . $name . '.pem';
    }
}
