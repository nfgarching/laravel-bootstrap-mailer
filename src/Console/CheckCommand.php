<?php

namespace Nfgarching\Mailer\Console;

use Illuminate\Console\Command;
use Nfgarching\Mailer\Security\CertificateStore;
use Symfony\Component\Mime\Crypto\SMimeSigner;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Prüft die Sicherheitskonfiguration des E-Mail-Versands.
 *
 *   php artisan nfmailer:check
 *   php artisan nfmailer:check --dkim-selector=google
 */
class CheckCommand extends Command
{
    protected $signature = 'nfmailer:check
        {--dkim-selector=google : DKIM-Selektor der Absenderdomain}
        {--verify : Empfänger-Zertifikate jetzt bei den Zertifizierungsstellen nachprüfen}';

    protected $description = 'Sicherheit des E-Mail-Versands prüfen (TLS, S/MIME, Links, DNS)';

    protected int $errors = 0;

    protected int $warnings = 0;

    public function handle(CertificateStore $store): int
    {
        $from = (string) config('mail.from.address');
        $domain = str_contains($from, '@') ? substr($from, strpos($from, '@') + 1) : null;

        $this->transport();
        $this->smime($from, $store);
        $this->links();
        $this->storage();

        if ($domain) {
            $this->dns($domain);
        }

        $this->newLine();
        $this->line("Ergebnis: {$this->errors} Fehler, {$this->warnings} Hinweis(e).");

        return $this->errors ? self::FAILURE : self::SUCCESS;
    }

    // ------------------------------------------------------------------ Transport

    protected function transport(): void
    {
        $this->section('Transport');

        $name = config('nfmailer.mailer') ?: config('mail.default');
        $cfg = (array) config("mail.mailers.{$name}", []);
        $transport = $cfg['transport'] ?? '?';

        $this->line("  Versandweg: Mailer „{$name}“ ({$transport})");

        if ($name === 'nfmailer') {
            $this->bad('NFMAILER_MAILER/MAIL_MAILER darf nicht „nfmailer“ sein.');

            return;
        }

        if ($transport !== 'smtp') {
            $this->warn_("Transport „{$transport}“: TLS-Prüfung nur für SMTP möglich.");

            return;
        }

        $port = (int) ($cfg['port'] ?? 0);
        $scheme = $cfg['scheme'] ?? ($port === 465 ? 'smtps' : 'smtp');
        $host = (string) ($cfg['host'] ?? '');

        if ($scheme === 'smtps') {
            $this->good("Implizites TLS ({$host}:{$port}) – kein Klartext möglich.");
        } elseif (config('nfmailer.require_tls', true)) {
            $this->good("STARTTLS wird erzwungen ({$host}:{$port}).");
        } else {
            $this->bad("Kein TLS-Zwang ({$host}:{$port}). NFMAILER_REQUIRE_TLS=true setzen oder Port 465 verwenden.");
        }

        if (empty($cfg['username'])) {
            $this->warn_('Kein SMTP-Benutzer gesetzt (Relay ohne Anmeldung?).');
        }
    }

    // ------------------------------------------------------------------ S/MIME

    protected function smime(string $from, CertificateStore $store): void
    {
        $this->section('S/MIME');

        $c = (array) config('nfmailer.smime', []);

        if (empty($c['certificate']) && empty($c['key'])) {
            $this->warn_('Keine Signatur konfiguriert (NFMAILER_SMIME_CERT / NFMAILER_SMIME_KEY).');
        } else {
            $this->smimeSigning($c, $from, $store);
        }

        if (! empty($c['encrypt'])) {
            $this->recipients($store);
        }
    }

    protected function recipients(CertificateStore $store): void
    {
        $verifier = $store->verifier();
        $trust = (array) config('nfmailer.smime.trust', []);

        if ($verifier) {
            ($trust['ocsp'] ?? true) && ! $verifier->openssl()
                ? $this->bad('Befehl „openssl“ fehlt – keine OCSP-Sperrprüfung möglich (im Dockerfile: apt-get install -y openssl).')
                : $this->good('Sperrprüfung per OCSP ' . (($trust['ocsp'] ?? true) ? 'aktiv' : 'abgeschaltet') . ', Kettenprüfung: ' . ($trust['chain'] ?? 'warn') . '.');

            $roots = count(glob(rtrim((string) ($trust['roots'] ?? ''), '/') . '/*.pem') ?: []);
            $this->line('  Vertrauensspeicher: ' . (($trust['system_roots'] ?? true) ? 'System' : '–') . " + {$roots} eigene Wurzel(n)");
        } else {
            $this->warn_('Empfänger-Zertifikate werden nicht bei der CA geprüft (smime.trust).');
        }

        if ($this->option('verify') && $verifier) {
            $this->line('  Prüfe alle Empfänger-Zertifikate bei den Zertifizierungsstellen …');
            foreach ($store->all() as $c) {
                $store->verify($c['email']);
            }
        }

        $all = $store->all();
        $this->line('  Empfänger-Zertifikate: ' . count($all));

        $expired = array_column(array_filter($all, fn ($x) => $x['expired']), 'email');
        $revoked = array_column(array_filter($all, fn ($x) => ($x['status']['ocsp_status'] ?? null) === 'revoked'), 'email');
        $untrusted = array_column(array_filter($all, fn ($x) => $x['status'] && ! $x['status']['chain_ok']), 'email');
        $unchecked = array_column(array_filter($all, fn ($x) => ! $x['status']), 'email');
        $stale = array_column(array_filter($all, fn ($x) => $x['status']
            && time() - (int) $x['status']['checked_at'] > 86400 * 30), 'email');

        $expired && $this->warn_('Abgelaufen: ' . implode(', ', $expired));
        $revoked && $this->bad('GESPERRT (werden nicht verwendet): ' . implode(', ', $revoked));
        $untrusted && $this->warn_('Aussteller nicht vertrauenswürdig: ' . implode(', ', $untrusted)
            . (($trust['chain'] ?? 'warn') === 'require' ? ' (werden nicht verwendet)' : ''));
        $verifier && $unchecked && $this->warn_('Noch nie geprüft: ' . implode(', ', $unchecked) . ' → nfmailer:check --verify');
        $stale && $this->warn_('Letzte Prüfung älter als 30 Tage: ' . implode(', ', $stale) . ' → nfmailer:check --verify');
    }

    protected function smimeSigning(array $c, string $from, CertificateStore $store): void
    {
        foreach (['certificate' => 'Zertifikat', 'key' => 'Privater Schlüssel'] as $k => $label) {
            if (empty($c[$k]) || ! is_readable($c[$k])) {
                $this->bad("{$label} nicht lesbar: " . ($c[$k] ?? '–'));

                return;
            }
        }

        // Schlüsseldatei darf nur für den Eigentümer lesbar sein
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($c['key']) & 0o077)) {
            $this->warn_('Der private Schlüssel ist für andere lesbar. Empfohlen: chmod 600 ' . $c['key']);
        }

        $info = $store->inspect((string) file_get_contents($c['certificate']));
        if (! $info) {
            $this->bad('Zertifikat ist kein gültiges PEM-Zertifikat.');

            return;
        }

        $daysLeft = (int) floor(($info['valid_to_ts'] - time()) / 86400);

        if ($info['expired']) {
            $this->bad("Zertifikat abgelaufen am {$info['valid_to']}.");
        } elseif ($daysLeft < 30) {
            $this->warn_("Zertifikat läuft in {$daysLeft} Tagen ab ({$info['valid_to']}).");
        } else {
            $this->good("Zertifikat „{$info['subject']}“ gültig bis {$info['valid_to']}.");
        }

        if ($from && ! in_array(mb_strtolower($from), $info['emails'], true)) {
            $this->bad("Absender {$from} steht nicht im Zertifikat (" . implode(', ', $info['emails']) . '). Empfänger sehen die Signatur als ungültig.');
        }

        $key = @openssl_pkey_get_private('file://' . realpath($c['key']), $c['passphrase'] ?? '');
        if (! $key) {
            $this->bad('Privater Schlüssel lässt sich nicht öffnen (Passphrase?).');

            return;
        }

        if (! openssl_x509_check_private_key('file://' . realpath($c['certificate']), $key)) {
            $this->bad('Zertifikat und privater Schlüssel passen nicht zusammen.');

            return;
        }

        // Probe-Signatur
        try {
            $mail = (new Email())->from($from ?: 'test@example.com')->to('test@example.com')->subject('Test')->text('Test');
            (new SMimeSigner($c['certificate'], $c['key'], ($c['passphrase'] ?? null) ?: null, ($c['extra_certs'] ?? null) ?: null))->sign($mail);
            $this->good('Probe-Signatur erfolgreich.');
        } catch (Throwable $e) {
            $this->bad('Probe-Signatur fehlgeschlagen: ' . $e->getMessage());
        }

        if (empty($c['extra_certs'])) {
            $this->warn_('Keine Zwischenzertifikate (NFMAILER_SMIME_CHAIN) – manche Mailprogramme können die Signatur dann nicht prüfen.');
        }
    }

    // ------------------------------------------------------------------ Links

    protected function links(): void
    {
        $this->section('Download-Links');

        $url = (string) config('app.url');
        str_starts_with($url, 'https://')
            ? $this->good("APP_URL nutzt HTTPS ({$url}).")
            : $this->bad("APP_URL ist nicht HTTPS ({$url}) – Links und PINs würden unverschlüsselt übertragen.");

        $days = (int) config('nfmailer.links.expires_days', 14);
        $days === 0 || $days > 30
            ? $this->warn_('Links laufen ' . ($days === 0 ? 'nie' : "erst nach {$days} Tagen") . ' ab.')
            : $this->good("Links laufen nach {$days} Tagen ab.");

        config('nfmailer.links.pin_default', true)
            ? $this->good('PIN-Schutz ist vorbelegt.')
            : $this->warn_('PIN-Schutz ist nicht vorbelegt (links.pin_default).');
    }

    // ------------------------------------------------------------------ Speicherung

    protected function storage(): void
    {
        $this->section('Protokoll');

        empty(config('app.key'))
            ? $this->bad('APP_KEY fehlt – das Protokoll kann nicht verschlüsselt werden.')
            : $this->good('Protokoll wird mit APP_KEY verschlüsselt. APP_KEY getrennt von Datenbank-Backups sichern!');

        if (config('nfmailer.queue')) {
            $this->good('Queue-Inhalte werden verschlüsselt (ShouldBeEncrypted).');
        }
    }

    // ------------------------------------------------------------------ DNS

    protected function dns(string $domain): void
    {
        $this->section("DNS ({$domain})");

        $txt = fn (string $host) => array_column(@dns_get_record($host, DNS_TXT) ?: [], 'txt');

        $records = $txt($domain);
        if ($records === [] && ! @dns_get_record($domain, DNS_NS)) {
            $this->warn_('DNS nicht abfragbar (keine Netzverbindung?) – Prüfung übersprungen.');

            return;
        }

        $spf = collect($records)->first(fn ($r) => str_starts_with(strtolower($r), 'v=spf1'));
        $spf
            ? $this->good("SPF: {$spf}")
            : $this->bad('Kein SPF-Eintrag. Für Google Workspace: v=spf1 include:_spf.google.com ~all');

        $dmarc = collect($txt("_dmarc.{$domain}"))->first(fn ($r) => str_starts_with(strtolower($r), 'v=dmarc1'));
        if (! $dmarc) {
            $this->bad('Kein DMARC-Eintrag (_dmarc). Einstieg: v=DMARC1; p=none; rua=mailto:dmarc@' . $domain);
        } elseif (preg_match('/p=none/i', $dmarc)) {
            $this->warn_("DMARC nur Beobachtung (p=none): {$dmarc}");
        } else {
            $this->good("DMARC: {$dmarc}");
        }

        $selector = (string) $this->option('dkim-selector');
        $dkim = collect($txt("{$selector}._domainkey.{$domain}"))->first(fn ($r) => str_contains(strtolower($r), 'p='));
        $dkim
            ? $this->good("DKIM-Schlüssel für Selektor „{$selector}“ vorhanden.")
            : $this->warn_("Kein DKIM-Schlüssel unter {$selector}._domainkey.{$domain} (anderer Selektor? --dkim-selector=…).");
    }

    // ------------------------------------------------------------------ Ausgabe

    protected function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    protected function good(string $msg): void
    {
        $this->line("  <fg=green>✔</> {$msg}");
    }

    protected function warn_(string $msg): void
    {
        $this->warnings++;
        $this->line("  <fg=yellow>!</> {$msg}");
    }

    protected function bad(string $msg): void
    {
        $this->errors++;
        $this->line("  <fg=red>✘</> {$msg}");
    }
}
