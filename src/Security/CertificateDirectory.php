<?php

namespace Nfgarching\Mailer\Security;

/**
 * Suche nach Empfänger-Zertifikaten in öffentlichen LDAP-Verzeichnissen der CAs
 * (D-Trust, DFN-PKI, TeleSec, SwissSign …), Liste in smime.directory.servers.
 *
 * Nur Zertifikate dieser CAs und nur, wenn der Inhaber der Veröffentlichung
 * zugestimmt hat – viele CAs (Sectigo, Certum, Actalis …) haben kein Verzeichnis.
 *
 * Datenschutz: Jede Suche schickt die Adresse an alle eingetragenen Verzeichnisse.
 * Deshalb nur auf ausdrücklichen Wunsch suchen (Befehl, Knopf im Modal).
 */
class CertificateDirectory
{
    /**
     * @param list<array{name: string, uri: string, base: string}> $servers
     */
    public function __construct(
        protected array $servers = [],
        protected bool $enabled = true,
        protected int $timeout = 5,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('nfmailer.smime.directory', []);

        return new self(
            array_values((array) ($c['servers'] ?? [])),
            (bool) ($c['enabled'] ?? true),
            (int) ($c['timeout'] ?? 5),
        );
    }

    /** Suche eingeschaltet, Verzeichnisse eingetragen und LDAP-Erweiterung vorhanden */
    public function enabled(): bool
    {
        return $this->enabled && $this->servers && function_exists('ldap_connect');
    }

    /**
     * Alle Verzeichnisse nach Zertifikaten für $email durchsuchen.
     *
     * @return array{certificates: list<string>, log: list<string>}
     *         certificates: gefundene Zertifikate (PEM), log: Ergebnis je Verzeichnis
     */
    public function lookup(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $certificates = [];
        $log = [];

        foreach ($this->servers as $server) {
            $name = $server['name'] ?? $server['uri'];

            try {
                $found = $this->search($server, $email);
                $log[] = $name . ': ' . ($found ? count($found) . ' Zertifikat(e)' : 'nichts gefunden');
                array_push($certificates, ...$found);
            } catch (\Throwable $e) {
                $log[] = $name . ': nicht erreichbar (' . $e->getMessage() . ')';
            }
        }

        return ['certificates' => $certificates, 'log' => $log];
    }

    /**
     * Ein Verzeichnis anonym nach (mail=$email) durchsuchen.
     *
     * @param  array{name?: string, uri: string, base: string}  $server
     * @return list<string> Zertifikate als PEM
     */
    protected function search(array $server, string $email): array
    {
        $ldap = @ldap_connect($server['uri']);
        if (! $ldap) {
            throw new \RuntimeException('ungültige Adresse');
        }

        try {
            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, $this->timeout);
            ldap_set_option($ldap, LDAP_OPT_TIMELIMIT, $this->timeout);

            if (! @ldap_bind($ldap)) {
                throw new \RuntimeException(ldap_error($ldap));
            }

            $filter = '(mail=' . ldap_escape($email, '', LDAP_ESCAPE_FILTER) . ')';
            $result = @ldap_search($ldap, $server['base'], $filter, ['userCertificate;binary', 'userSMIMECertificate;binary'], 0, 20, $this->timeout);

            if (! $result) {
                throw new \RuntimeException(ldap_error($ldap));
            }

            $pems = [];
            for ($entry = ldap_first_entry($ldap, $result); $entry; $entry = ldap_next_entry($ldap, $entry)) {
                foreach (array_keys((array) ldap_get_attributes($ldap, $entry)) as $attr) {
                    if (! is_string($attr) || ! preg_match('/^(userCertificate|userSMIMECertificate)/i', $attr)) {
                        continue;
                    }

                    $values = @ldap_get_values_len($ldap, $entry, $attr) ?: [];
                    unset($values['count']);

                    foreach ($values as $der) {
                        array_push($pems, ...CertificateStore::extract($der));
                    }
                }
            }

            return $pems;
        } finally {
            @ldap_unbind($ldap);
        }
    }
}
