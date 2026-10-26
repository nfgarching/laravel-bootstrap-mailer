<?php

namespace Nfgarching\Mailer\Console;

use Illuminate\Console\Command;
use Nfgarching\Mailer\Security\CertificateStore;
use Throwable;

/**
 * Verwaltung der Empfänger-Zertifikate für die S/MIME-Verschlüsselung.
 *
 *   php artisan nfmailer:cert import mandant.cer      (.cer/.crt/.pem/.p7s/.p7b)
 *   php artisan nfmailer:cert import signierte-mail.eml
 *   php artisan nfmailer:cert list
 *   php artisan nfmailer:cert show mandant@example.de
 *   php artisan nfmailer:cert verify [mandant@example.de]   Kette + Sperrstatus neu prüfen
 *   php artisan nfmailer:cert trust wurzel.pem              Wurzelzertifikat einer CA hinterlegen
 *   php artisan nfmailer:cert remove mandant@example.de
 */
class CertCommand extends Command
{
    protected $signature = 'nfmailer:cert
        {action : import | list | show | verify | trust | remove}
        {value? : Datei (import, trust) oder E-Mail-Adresse (show, verify, remove)}
        {--email= : Beim Import nur diese Adresse übernehmen}';

    protected $description = 'S/MIME-Zertifikate der Empfänger verwalten';

    public function handle(CertificateStore $store): int
    {
        return match ($this->argument('action')) {
            'import' => $this->import($store),
            'list'   => $this->list($store),
            'show'   => $this->show($store),
            'verify' => $this->verify($store),
            'trust'  => $this->trust($store),
            'remove' => $this->remove($store),
            default  => $this->fail('Unbekannte Aktion. Erlaubt: import, list, show, verify, trust, remove'),
        };
    }

    protected function import(CertificateStore $store): int
    {
        $file = (string) $this->argument('value');

        if (! is_file($file)) {
            $this->error("Datei nicht gefunden: {$file}");

            return self::FAILURE;
        }

        try {
            $imported = $store->import((string) file_get_contents($file), $this->option('email'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($imported as $info) {
            $this->info('Importiert für: ' . implode(', ', $info['emails']));
            $this->details($info);
            $this->statusLines($info['status'] ?? []);
        }

        $this->newLine();
        $this->line('Bei sensiblen Mandaten den Fingerabdruck kurz telefonisch mit dem Empfänger abgleichen.');

        return self::SUCCESS;
    }

    protected function list(CertificateStore $store): int
    {
        $rows = array_map(fn ($c) => [
            $c['email'],
            $c['subject'],
            $c['issuer'],
            $c['valid_to'],
            $c['expired'] ? '<fg=red>abgelaufen</>' : '<fg=green>gültig</>',
            $this->shortStatus($c['status']),
        ], $store->all());

        if (! $rows) {
            $this->line('Noch keine Zertifikate hinterlegt.');

            return self::SUCCESS;
        }

        $this->table(['E-Mail', 'Inhaber', 'Aussteller', 'Gültig bis', 'Status', 'CA-Prüfung'], $rows);

        return self::SUCCESS;
    }

    protected function show(CertificateStore $store): int
    {
        $email = (string) $this->argument('value');
        $info = $store->show($email);

        if (! $info) {
            $this->warn("Kein Zertifikat für {$email} hinterlegt.");

            return self::FAILURE;
        }

        $this->line("<options=bold>{$email}</>");
        $this->details($info);
        $this->line('  Status:      ' . ($info['expired'] ? '<fg=red>abgelaufen</>' : '<fg=green>gültig</>'));
        $this->statusLines($store->status($email));

        if ($reason = $store->unusableReason($email)) {
            $this->line("  <fg=red>Wird nicht zum Verschlüsseln verwendet: {$reason}</>");
        }

        return self::SUCCESS;
    }

    protected function verify(CertificateStore $store): int
    {
        if (! $store->verifier()) {
            $this->warn('Prüfung ist abgeschaltet (smime.trust.chain=off und ocsp=false).');

            return self::FAILURE;
        }

        $emails = $this->argument('value')
            ? [(string) $this->argument('value')]
            : array_column($store->all(), 'email');

        $problems = 0;
        foreach ($emails as $email) {
            $status = $store->verify($email);

            if ($status === null) {
                $this->warn("Kein Zertifikat für {$email} hinterlegt.");
                $problems++;

                continue;
            }

            $this->line("<options=bold>{$email}</>");
            $this->statusLines($status);

            if ($reason = $store->unusableReason($email)) {
                $this->line("  <fg=red>Wird nicht zum Verschlüsseln verwendet: {$reason}</>");
                $problems++;
            }
        }

        return $problems ? self::FAILURE : self::SUCCESS;
    }

    protected function trust(CertificateStore $store): int
    {
        $file = (string) $this->argument('value');
        $dir = (string) config('nfmailer.smime.trust.roots');

        if (! is_file($file)) {
            $this->error("Datei nicht gefunden: {$file}");

            return self::FAILURE;
        }

        $added = 0;
        $found = 0;
        foreach (CertificateStore::extract((string) file_get_contents($file)) as $pem) {
            $d = openssl_x509_parse($pem);
            $isRoot = $d && $d['subject'] == $d['issuer']
                && str_contains((string) ($d['extensions']['basicConstraints'] ?? ''), 'CA:TRUE');

            if (! $isRoot) {
                continue;
            }

            $found++;
            $info = $store->inspect($pem);
            $this->line('<options=bold>' . ($d['subject']['CN'] ?? $info['subject']) . '</>');
            $this->line("  Gültig bis:  {$info['valid_to']}");
            $this->line("  SHA-256:     {$info['fingerprint']}");

            if (! $this->confirm('Den Fingerabdruck mit der Website der CA abgeglichen und als vertrauenswürdig hinterlegen?', false)) {
                continue;
            }

            is_dir($dir) || mkdir($dir, 0750, true);
            file_put_contents($dir . '/' . strtolower(str_replace(':', '', substr($info['fingerprint'], 0, 23))) . '.pem', $pem);
            $added++;
        }

        if (! $added) {
            $this->warn($found
                ? 'Nichts übernommen.'
                : 'Die Datei enthält kein Wurzelzertifikat (nur selbstsignierte CA-Zertifikate sind möglich).');

            return self::FAILURE;
        }

        $this->info("{$added} Wurzelzertifikat(e) hinterlegt. Jetzt prüfen: php artisan nfmailer:cert verify");

        return self::SUCCESS;
    }

    protected function statusLines(array $status): void
    {
        if (! $status) {
            $this->line('  CA-Prüfung:  <fg=yellow>nicht durchgeführt</>');

            return;
        }

        $this->line('  Kette:       ' . ($status['chain_ok'] ? '<fg=green>✔</> ' : '<fg=yellow>!</> ') . $status['chain_message']);

        $icon = match ($status['ocsp_status']) {
            'good'    => '<fg=green>✔</> ',
            'revoked' => '<fg=red>✘</> ',
            default   => '<fg=yellow>?</> ',
        };
        $this->line('  Sperrstatus: ' . $icon . $status['ocsp_message']
            . ' (' . date('d.m.Y H:i', (int) $status['checked_at']) . ')');
    }

    protected function shortStatus(array $status): string
    {
        if (! $status) {
            return '<fg=yellow>–</>';
        }
        if ($status['ocsp_status'] === 'revoked') {
            return '<fg=red>GESPERRT</>';
        }

        return ($status['chain_ok'] ? '<fg=green>Kette ✔</>' : '<fg=yellow>Kette ?</>')
            . ' · ' . match ($status['ocsp_status']) {
                'good'  => '<fg=green>OCSP ✔</>',
                default => '<fg=yellow>OCSP ?</>',
            };
    }

    protected function details(array $info): void
    {
        $this->line("  Inhaber:     {$info['subject']}");
        $this->line("  Aussteller:  {$info['issuer']}");
        $this->line("  Gültig bis:  {$info['valid_to']}");
        $this->line("  SHA-256:     {$info['fingerprint']}");
    }

    protected function remove(CertificateStore $store): int
    {
        $email = (string) $this->argument('value');

        if ($store->remove($email)) {
            $this->info("Zertifikat für {$email} entfernt.");

            return self::SUCCESS;
        }

        $this->warn("Kein Zertifikat für {$email} hinterlegt.");

        return self::FAILURE;
    }
}
