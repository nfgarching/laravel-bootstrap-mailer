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
 *   php artisan nfmailer:cert remove mandant@example.de
 */
class CertCommand extends Command
{
    protected $signature = 'nfmailer:cert
        {action : import | list | show | remove}
        {value? : Datei (import) oder E-Mail-Adresse (show, remove)}
        {--email= : Beim Import nur diese Adresse übernehmen}';

    protected $description = 'S/MIME-Zertifikate der Empfänger verwalten';

    public function handle(CertificateStore $store): int
    {
        return match ($this->argument('action')) {
            'import' => $this->import($store),
            'list'   => $this->list($store),
            'show'   => $this->show($store),
            'remove' => $this->remove($store),
            default  => $this->fail('Unbekannte Aktion. Erlaubt: import, list, show, remove'),
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
        ], $store->all());

        if (! $rows) {
            $this->line('Noch keine Zertifikate hinterlegt.');

            return self::SUCCESS;
        }

        $this->table(['E-Mail', 'Inhaber', 'Aussteller', 'Gültig bis', 'Status'], $rows);

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

        return self::SUCCESS;
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
