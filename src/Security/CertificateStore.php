<?php

namespace Nfgarching\Mailer\Security;

use RuntimeException;

/**
 * Ablage der S/MIME-Zertifikate von Empfängern (öffentliche Schlüssel).
 *
 * Je Adresse im konfigurierten Verzeichnis:
 *   <adresse>.pem          Zertifikat
 *   <adresse>.chain        Zwischenzertifikate der CA (für Ketten- und OCSP-Prüfung)
 *   <adresse>.status.json  Ergebnis der letzten Prüfung (Kette, Sperrstatus)
 *
 * Zertifikate sind nicht geheim; geheim ist nur der eigene private Schlüssel.
 */
class CertificateStore
{
    /**
     * @param array{chain?: string, check_before_send?: bool, cache_hours?: int} $policy
     *        chain: 'require' = nur Zertifikate mit geprüfter Kette, 'warn' = übernehmen mit Hinweis, 'off'
     */
    public function __construct(
        protected string $directory,
        protected ?CertificateVerifier $verifier = null,
        protected array $policy = [],
    ) {}

    public static function fromConfig(): self
    {
        $trust = (array) config('nfmailer.smime.trust', []);

        return new self(
            (string) config('nfmailer.smime.certificates', storage_path('app/private/nfmailer/certs')),
            ($trust['chain'] ?? 'warn') === 'off' && ! ($trust['ocsp'] ?? true) ? null : CertificateVerifier::fromConfig(),
            $trust,
        );
    }

    /**
     * Pfad zum verwendbaren Zertifikat oder null:
     * nicht abgelaufen, nicht gesperrt, und – bei chain=require – mit geprüfter Kette.
     */
    public function path(string $email): ?string
    {
        return $this->unusableReason($email) === null ? $this->file($email) : null;
    }

    /** Warum das Zertifikat einer Adresse nicht verwendbar ist, oder null */
    public function unusableReason(string $email): ?string
    {
        $file = $this->file($email);

        if (! is_file($file)) {
            return 'kein Zertifikat';
        }

        $info = $this->inspect((string) file_get_contents($file));

        if (! $info) {
            return 'Zertifikat unlesbar';
        }
        if ($info['expired']) {
            return 'abgelaufen am ' . $info['valid_to'];
        }

        $status = $this->status($email);

        if (($status['ocsp_status'] ?? null) === CertificateVerifier::REVOKED) {
            return 'von der Zertifizierungsstelle gesperrt';
        }
        if (($this->policy['chain'] ?? 'warn') === 'require' && ! ($status['chain_ok'] ?? false)) {
            return 'Aussteller nicht vertrauenswürdig';
        }

        return null;
    }

    /**
     * Prüft vor dem Versand erneut beim Aussteller, wenn die letzte Prüfung älter
     * als cache_hours ist (smime.trust.check_before_send).
     */
    public function ensureFresh(string $email): void
    {
        if (! $this->verifier || ! ($this->policy['check_before_send'] ?? true) || ! is_file($this->file($email))) {
            return;
        }

        $age = time() - (int) ($this->status($email)['checked_at'] ?? 0);

        if ($age > 3600 * (int) ($this->policy['cache_hours'] ?? 24)) {
            $this->verify($email);
        }
    }

    /**
     * Kette und Sperrstatus jetzt prüfen und Ergebnis speichern.
     *
     * @return array{chain_ok: bool, chain_message: string, ocsp_status: string, ocsp_message: string, checked_at: int}|null
     */
    public function verify(string $email): ?array
    {
        $file = $this->file($email);

        if (! $this->verifier || ! is_file($file)) {
            return null;
        }

        $pem = (string) file_get_contents($file);
        $chainFile = $this->chainFile($email);
        $intermediates = is_file($chainFile) ? self::extract((string) file_get_contents($chainFile)) : [];

        $status = $this->check($pem, $intermediates);
        $this->writeChain($email, $status['intermediates']);
        unset($status['intermediates']);
        $this->writeStatus($email, $status);

        return $status;
    }

    /** Gespeichertes Prüfergebnis einer Adresse */
    public function status(string $email): array
    {
        $f = $this->statusFile($email);

        return is_file($f) ? (array) json_decode((string) file_get_contents($f), true) : [];
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
     * Ist eine Prüfung konfiguriert (smime.trust), werden Kette und Sperrstatus beim
     * Aussteller geprüft: gesperrte Zertifikate werden immer abgelehnt, solche ohne
     * vertrauenswürdige Kette nur bei chain=require.
     *
     * @return list<array{emails: list<string>, subject: string, issuer: string, valid_to: string, fingerprint: string, status: ?array}>
     */
    public function import(string $contents, ?string $onlyEmail = null): array
    {
        $candidates = [];
        $expired = [];
        $rejected = [];

        $all = self::extract($contents);
        $intermediates = $this->verifier
            ? array_values(array_filter($all, fn ($c) => $this->verifier->isCa($c)))
            : [];

        foreach ($all as $pem) {
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

            $status = $this->verifier ? $this->check($pem, $intermediates) : null;

            if ($status && $status['ocsp_status'] === CertificateVerifier::REVOKED) {
                $rejected[] = implode(', ', $emails) . ': ' . $status['ocsp_message'];

                continue;
            }
            if ($status && ! $status['chain_ok'] && ($this->policy['chain'] ?? 'warn') === 'require') {
                $rejected[] = implode(', ', $emails) . ': ' . $status['chain_message']
                    . ' (Wurzelzertifikat mit „nfmailer:cert trust“ hinterlegen?)';

                continue;
            }

            $candidates[$info['fingerprint']] = ['pem' => $pem, 'emails' => $emails, 'status' => $status] + $info;
        }

        if (! $candidates) {
            throw new RuntimeException(match (true) {
                (bool) $rejected    => 'Nicht übernommen – ' . implode('; ', $rejected),
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
            $status = $c['status'];
            $chain = $status['intermediates'] ?? [];
            unset($status['intermediates']);

            foreach ($c['emails'] as $email) {
                file_put_contents($this->file($email), $c['pem']);
                $this->writeChain($email, $chain);
                $status ? $this->writeStatus($email, $status) : @unlink($this->statusFile($email));
            }

            $result[] = array_intersect_key($c, array_flip(['emails', 'subject', 'issuer', 'valid_to', 'fingerprint']))
                + ['status' => $status];
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
        $p7 = tempnam(sys_get_temp_dir(), 'nfm');

        try {
            // Zeilenenden vereinheitlichen (gespeicherte .eml haben mal LF, mal CRLF)
            file_put_contents($in, preg_replace("/\r?\n/", "\r\n", $mail));

            $ok = openssl_pkcs7_verify($in, PKCS7_NOVERIFY, $out, [], null, null, $p7);

            if ($ok !== true) {
                $errors = '';
                while ($e = openssl_error_string()) {
                    $errors .= $e . ' ';
                }

                throw new RuntimeException(match (true) {
                    str_contains($errors, 'signer certificate not found')
                        => 'Die E-Mail wurde ohne mitgeschicktes Zertifikat signiert. Bitte den Absender um das Zertifikat als .cer-Datei bitten.',
                    $ok === false
                        => 'Die Signatur der E-Mail ist ungültig (Mail verändert?). Zertifikat nicht übernommen.',
                    default
                        => 'Die E-Mail enthält keine lesbare S/MIME-Signatur: ' . trim($errors),
                });
            }

            preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($out), $m);
            $signers = array_map(fn ($c) => $c . "\n", $m[0]);

            // Mitgeschickte Zwischenzertifikate (für die Kettenprüfung) anhängen
            $all = self::fromPkcs7Pem((string) file_get_contents($p7));
            $norm = fn ($c) => preg_replace('/\s+/', '', $c);
            $known = array_map($norm, $signers);
            foreach ($all as $c) {
                if (! in_array($norm($c), $known, true)) {
                    $signers[] = $c;
                    $known[] = $norm($c);
                }
            }

            return $signers;
        } finally {
            @unlink($in);
            @unlink($out);
            @unlink($p7);
        }
    }

    public function remove(string $email): bool
    {
        $file = $this->file($email);
        @unlink($this->chainFile($email));
        @unlink($this->statusFile($email));

        return is_file($file) && unlink($file);
    }

    /** @return list<array{email: string, subject: string, valid_to: string, expired: bool, status: array}> */
    public function all(): array
    {
        $out = [];

        foreach (glob(rtrim($this->directory, '/') . '/*.pem') ?: [] as $file) {
            $email = basename($file, '.pem');
            if ($info = $this->inspect((string) file_get_contents($file))) {
                $out[] = ['email' => $email] + $info + ['status' => $this->status($email)];
            }
        }

        return $out;
    }

    public function verifier(): ?CertificateVerifier
    {
        return $this->verifier;
    }

    /** @param list<string> $intermediates */
    protected function check(string $pem, array $intermediates): array
    {
        $chain = $this->verifier->chain($pem, $intermediates);
        $ocsp = $this->verifier->ocsp($pem, $chain['intermediates']);

        return [
            'chain_ok'      => $chain['ok'],
            'chain_message' => $chain['message'],
            'ocsp_status'   => $ocsp['status'],
            'ocsp_message'  => $ocsp['message'],
            'checked_at'    => $ocsp['checked_at'],
            'intermediates' => $chain['intermediates'],
        ];
    }

    /** @param list<string> $intermediates */
    protected function writeChain(string $email, array $intermediates): void
    {
        $intermediates
            ? file_put_contents($this->chainFile($email), implode('', $intermediates))
            : @unlink($this->chainFile($email));
    }

    protected function writeStatus(string $email, array $status): void
    {
        file_put_contents($this->statusFile($email), json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    protected function chainFile(string $email): string
    {
        return substr($this->file($email), 0, -4) . '.chain';
    }

    protected function statusFile(string $email): string
    {
        return substr($this->file($email), 0, -4) . '.status.json';
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
