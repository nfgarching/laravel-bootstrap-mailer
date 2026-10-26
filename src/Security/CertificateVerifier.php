<?php

namespace Nfgarching\Mailer\Security;

/**
 * Prüft Empfänger-Zertifikate gegen die Zertifizierungsstellen:
 *
 *  - Vertrauenskette: bis zu einem vertrauenswürdigen Wurzelzertifikat
 *    (System-Zertifikatsspeicher + eigene Wurzeln im Verzeichnis smime.trust.roots)
 *  - Sperrstatus per OCSP beim Aussteller (über den openssl-Befehl, da PHP selbst
 *    kein OCSP kann). Fehlende Zwischenzertifikate werden über die im Zertifikat
 *    hinterlegte „CA Issuers“-Adresse nachgeladen.
 */
class CertificateVerifier
{
    public const GOOD = 'good';
    public const REVOKED = 'revoked';
    public const UNKNOWN = 'unknown';

    /**
     * @param array{roots?: ?string, system_roots?: bool, ocsp?: bool, timeout?: int, openssl?: string} $config
     */
    public function __construct(protected array $config = []) {}

    public static function fromConfig(): self
    {
        return new self((array) config('nfmailer.smime.trust', []));
    }

    // ------------------------------------------------------------------ Kette

    /**
     * @param  list<string>  $intermediates  PEM-Zwischenzertifikate (z. B. aus der signierten Mail)
     * @return array{ok: bool, message: string, intermediates: list<string>}
     */
    public function chain(string $leaf, array $intermediates = []): array
    {
        $intermediates = $this->completeIntermediates($leaf, $intermediates);
        $cainfo = $this->trustLocations();

        if (! $cainfo) {
            return ['ok' => false, 'message' => 'Keine Wurzelzertifikate verfügbar.', 'intermediates' => $intermediates];
        }

        $untrusted = null;
        if ($intermediates) {
            $untrusted = tempnam(sys_get_temp_dir(), 'nfm');
            file_put_contents($untrusted, implode('', $intermediates));
        }

        try {
            $result = @openssl_x509_checkpurpose($leaf, X509_PURPOSE_SMIME_ENCRYPT, $cainfo, $untrusted);
        } finally {
            $untrusted && @unlink($untrusted);
        }

        return match ($result) {
            true    => ['ok' => true, 'message' => 'Kette bis zu vertrauenswürdiger Wurzel geprüft.', 'intermediates' => $intermediates],
            false   => ['ok' => false, 'message' => 'Aussteller ist nicht vertrauenswürdig oder Kette unvollständig.', 'intermediates' => $intermediates],
            default => ['ok' => false, 'message' => 'Kettenprüfung fehlgeschlagen: ' . (openssl_error_string() ?: 'unbekannter Fehler'), 'intermediates' => $intermediates],
        };
    }

    /** @return list<string> Dateien/Verzeichnisse für openssl_x509_checkpurpose */
    public function trustLocations(): array
    {
        $out = [];

        if ($this->config['system_roots'] ?? true) {
            $loc = openssl_get_cert_locations();
            foreach ([$loc['default_cert_file'] ?? null, $loc['default_cert_dir'] ?? null] as $p) {
                if ($p && file_exists($p)) {
                    $out[] = $p;
                }
            }
        }

        $roots = $this->config['roots'] ?? null;
        if ($roots && is_dir($roots)) {
            foreach (glob(rtrim($roots, '/') . '/*.pem') ?: [] as $file) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * Ergänzt fehlende Zwischenzertifikate über die AIA-Angabe („CA Issuers“).
     *
     * @param  list<string>  $have
     * @return list<string>
     */
    public function completeIntermediates(string $leaf, array $have): array
    {
        $have = array_values(array_filter($have, fn ($c) => $c !== $leaf && $this->isCa($c)));
        $current = $leaf;

        for ($depth = 0; $depth < 4; $depth++) {
            if ($this->isSelfIssued($current)) {
                break;
            }
            if ($issuer = $this->findIssuer($current, $have)) {
                $current = $issuer;

                continue;
            }
            $fetched = $this->fetchIssuer($current);
            if (! $fetched || $this->isSelfIssued($fetched)) {
                break;  // Wurzel nicht übernehmen – die muss aus dem Vertrauensspeicher kommen
            }
            $have[] = $fetched;
            $current = $fetched;
        }

        return $have;
    }

    /** @param list<string> $pool */
    public function findIssuer(string $cert, array $pool): ?string
    {
        $issuer = openssl_x509_parse($cert)['issuer'] ?? null;

        foreach ($pool as $candidate) {
            if ($candidate !== $cert && (openssl_x509_parse($candidate)['subject'] ?? null) == $issuer
                && openssl_x509_verify($cert, openssl_pkey_get_public($candidate)) === 1) {
                return $candidate;
            }
        }

        return null;
    }

    protected function fetchIssuer(string $cert): ?string
    {
        $url = $this->aia($cert, 'CA Issuers');

        if (! $url || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $data = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => (int) ($this->config['timeout'] ?? 10), 'follow_location' => 1, 'max_redirects' => 3],
        ]));

        if (! $data) {
            return null;
        }

        $certs = CertificateStore::extract($data);   // DER, PEM oder PKCS#7

        foreach ($certs as $c) {
            if (openssl_x509_verify($cert, openssl_pkey_get_public($c)) === 1) {
                return $c;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ OCSP

    /**
     * Fragt den Sperrstatus beim OCSP-Dienst des Ausstellers ab.
     *
     * @param  list<string>  $intermediates
     * @return array{status: string, message: string, checked_at: int}
     */
    public function ocsp(string $leaf, array $intermediates = []): array
    {
        $result = fn (string $status, string $msg) => ['status' => $status, 'message' => $msg, 'checked_at' => time()];

        if (! ($this->config['ocsp'] ?? true)) {
            return $result(self::UNKNOWN, 'OCSP-Prüfung ist abgeschaltet.');
        }

        $url = $this->aia($leaf, 'OCSP');
        if (! $url) {
            return $result(self::UNKNOWN, 'Zertifikat nennt keinen OCSP-Dienst.');
        }

        $issuer = $this->findIssuer($leaf, $this->completeIntermediates($leaf, $intermediates));
        if (! $issuer) {
            return $result(self::UNKNOWN, 'Ausstellerzertifikat nicht gefunden – OCSP nicht möglich.');
        }

        $openssl = $this->openssl();
        if (! $openssl) {
            return $result(self::UNKNOWN, 'Der Befehl „openssl“ ist nicht installiert.');
        }

        $dir = sys_get_temp_dir() . '/nfm-ocsp-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        file_put_contents("$dir/leaf.pem", $leaf);
        file_put_contents("$dir/issuer.pem", $issuer);

        $cmd = [$openssl, 'ocsp', '-issuer', "$dir/issuer.pem", '-cert', "$dir/leaf.pem", '-url', $url,
            '-timeout', (string) (int) ($this->config['timeout'] ?? 10), '-no_nonce'];

        // Antwort-Signatur gegen Aussteller + Vertrauensspeicher prüfen
        $bundle = $this->bundle($dir, array_merge([$issuer], $intermediates));
        $cmd = array_merge($cmd, ['-CAfile', $bundle]);

        [$code, $out] = $this->run($cmd);
        array_map('unlink', glob("$dir/*") ?: []);
        @rmdir($dir);

        if (preg_match('/leaf\.pem:\s*(good|revoked|unknown)/i', $out, $m)) {
            $status = strtolower($m[1]);
            $verified = str_contains($out, 'Response verify OK');

            if (! $verified) {
                return $result(self::UNKNOWN, 'OCSP-Antwort nicht vertrauenswürdig signiert.');
            }

            return match ($status) {
                'good'    => $result(self::GOOD, 'Nicht gesperrt (OCSP).'),
                'revoked' => $result(self::REVOKED, 'Von der Zertifizierungsstelle GESPERRT'
                    . (preg_match('/Revocation Time:\s*(.+)/', $out, $t) ? ' seit ' . trim($t[1]) : '') . '.'),
                default   => $result(self::UNKNOWN, 'Der OCSP-Dienst kennt das Zertifikat nicht.'),
            };
        }

        return $result(self::UNKNOWN, 'OCSP-Dienst nicht erreichbar oder Fehler: ' . trim(strtok($out, "\n") ?: "Exit {$code}"));
    }

    // ------------------------------------------------------------------ Hilfen

    public function aia(string $cert, string $type): ?string
    {
        $aia = (string) (openssl_x509_parse($cert)['extensions']['authorityInfoAccess'] ?? '');

        return preg_match('/' . preg_quote($type, '/') . '\s*-\s*URI:(\S+)/i', $aia, $m) ? $m[1] : null;
    }

    public function isCa(string $cert): bool
    {
        return str_contains((string) (openssl_x509_parse($cert)['extensions']['basicConstraints'] ?? ''), 'CA:TRUE');
    }

    public function isSelfIssued(string $cert): bool
    {
        $d = openssl_x509_parse($cert);

        return $d && $d['subject'] == $d['issuer'];
    }

    public function openssl(): ?string
    {
        $bin = $this->config['openssl'] ?? 'openssl';

        if (str_contains($bin, '/')) {
            return is_executable($bin) ? $bin : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable("$dir/$bin")) {
                return "$dir/$bin";
            }
        }

        return null;
    }

    /** @param list<string> $extra */
    protected function bundle(string $dir, array $extra): string
    {
        $pem = implode('', $extra);

        foreach ($this->trustLocations() as $loc) {
            if (is_file($loc)) {
                $pem .= file_get_contents($loc);
            }
        }

        file_put_contents("$dir/ca.pem", $pem);

        return "$dir/ca.pem";
    }

    /** @return array{0: int, 1: string} */
    protected function run(array $cmd): array
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($proc)) {
            return [-1, 'openssl konnte nicht gestartet werden'];
        }

        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $out];
    }
}
