<?php

namespace Nfgarching\Mailer\Support;

use Nfgarching\Mailer\Data\Recipient;

/**
 * Zerlegt freie Eingaben wie
 *   "a@b.de; Müller, Hans <h.mueller@x.de>, \"Kanzlei X\" <info@x.de>"
 * in einzelne Empfänger. Kommas innerhalb von Namen vor <…> sind erlaubt.
 */
class AddressParser
{
    private const PATTERN = '/(?:"([^"]*)"|([^<;\n"]*?))\s*<([^<>\s]+)>|([^\s,;<>"]+@[^\s,;<>"]+)/u';

    /**
     * @return array{valid: list<Recipient>, invalid: list<string>}
     */
    public static function parse(string $input): array
    {
        $valid = [];

        preg_match_all(self::PATTERN, $input, $m, PREG_SET_ORDER);

        foreach ($m as $hit) {
            $email = ($hit[4] ?? '') !== '' ? $hit[4] : ($hit[3] ?? '');
            $name  = ($hit[1] ?? '') !== '' ? $hit[1] : ($hit[2] ?? '');
            $name  = trim($name, " \t,;");

            $valid[] = Recipient::make($email, $name !== '' ? $name : null);
        }

        // Was nach dem Herausschneiden übrig bleibt, ist ungültig
        $rest = preg_replace(self::PATTERN, ' ', $input);
        $invalid = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) $rest))));

        // E-Mail-Syntax prüfen
        foreach ($valid as $i => $r) {
            if (! self::isValid($r->email)) {
                $invalid[] = $r->email;
                unset($valid[$i]);
            }
        }

        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    public static function isValid(string $email): bool
    {
        // Umlaut-Domains (müller.de) in Punycode umwandeln, sonst lehnt filter_var sie ab
        if (function_exists('idn_to_ascii') && str_contains($email, '@')) {
            [$local, $domain] = explode('@', $email, 2);
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            $email = $local . '@' . ($ascii ?: $domain);
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE);
    }

    /** Akzeptiert "a@b.de", "Name <a@b.de>" oder ['email' => …, 'name' => …]. */
    public static function one(string|array|null $value): ?Recipient
    {
        if (is_array($value)) {
            $email = $value['email'] ?? null;

            return $email && self::isValid(trim($email))
                ? Recipient::make($email, $value['name'] ?? null, $value['hint'] ?? null, $value['source'] ?? null)
                : null;
        }

        if (! $value) {
            return null;
        }

        return self::parse($value)['valid'][0] ?? null;
    }
}
