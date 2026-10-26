<?php

namespace Nfgarching\Mailer\Console;

use Illuminate\Console\Command;
use Nfgarching\Mailer\Security\CertificateStore;
use Throwable;

/**
 * Verwaltung der Empfänger-Zertifikate für die S/MIME-Verschlüsselung.
 *
 *   php artisan nfmailer:cert import mandant.cer
 *   php artisan nfmailer:cert list
 *   php artisan nfmailer:cert remove mandant@example.de
 */
class CertCommand extends Command
{
    protected $signature = 'nfmailer:cert
        {action : import | list | remove}
        {value? : Datei (import) oder E-Mail-Adresse (remove)}
        {--email= : Beim Import nur diese Adresse übernehmen}';

    protected $description = 'S/MIME-Zertifikate der Empfänger verwalten';

    public function handle(CertificateStore $store): int
    {
        return match ($this->argument('action')) {
            'import' => $this->import($store),
            'list'   => $this->list($store),
            'remove' => $this->remove($store),
            default  => $this->fail('Unbekannte Aktion. Erlaubt: import, list, remove'),
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
            $info = $store->import((string) file_get_contents($file), $this->option('email'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Importiert für: ' . implode(', ', $info['emails']));
        $this->line("Inhaber: {$info['subject']} · gültig bis {$info['valid_to']}");

        return self::SUCCESS;
    }

    protected function list(CertificateStore $store): int
    {
        $rows = array_map(fn ($c) => [
            $c['email'],
            $c['subject'],
            $c['valid_to'],
            $c['expired'] ? '<fg=red>abgelaufen</>' : '<fg=green>gültig</>',
        ], $store->all());

        if (! $rows) {
            $this->line('Noch keine Zertifikate hinterlegt.');

            return self::SUCCESS;
        }

        $this->table(['E-Mail', 'Inhaber', 'Gültig bis', 'Status'], $rows);

        return self::SUCCESS;
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
