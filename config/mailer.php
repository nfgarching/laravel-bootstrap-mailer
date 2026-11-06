<?php

use Nfgarching\Mailer\Sources\FavoriteRecipientSource;
use Nfgarching\Mailer\Sources\ModelRecipientSource;

return [

    /*
    |--------------------------------------------------------------------------
    | Adressquellen
    |--------------------------------------------------------------------------
    | Jede Quelle implementiert Nfgarching\Mailer\Contracts\RecipientSource.
    | Alle Quellen werden bei der Suche gemeinsam abgefragt; Ergebnisse werden
    | nach E-Mail-Adresse zusammengeführt. Reihenfolge = Priorität.
    |
    | Freie Eingabe ist immer möglich und braucht keine Quelle.
    */
    'sources' => [

        // Beteiligte der aktuellen Akte (öffnen mit 'context' => ['akte' => NR])
        [
            'class' => 'App\\Mail\\Recipients\\AkteBeteiligteSource',
            'label' => 'Beteiligte der Akte',
        ],

        // Favoriten (Tabelle mail_favorites): gemeinsame + persönliche des Users
        [
            'class' => FavoriteRecipientSource::class,
            'label' => 'Favoriten',
        ],

        // Bearbeiter: Logins mit users.bearbeiter_id (Adresse = Login-E-Mail)
        [
            'class' => 'App\\Mail\\Recipients\\BearbeiterSource',
            'label' => 'Bearbeiter',
        ],

        // Adressbuch: E-Mail-Adressen der Namen (Legacy-Tabelle NamenKom)
        [
            'class' => 'App\\Mail\\Recipients\\NamKomSource',
            'label' => 'Adressbuch',
        ],

        // Allgemeines Adressbuch (beliebiges Eloquent-Model)
        // [
        //     'class'  => ModelRecipientSource::class,
        //     'label'  => 'Adressbuch',
        //     'model'  => 'App\\Models\\Kontakt',
        //     'email'  => 'email',
        //     'name'   => ['vorname', 'nachname'],   // werden mit Leerzeichen verbunden
        //     'search' => ['vorname', 'nachname', 'firma', 'email'],
        //     'hint'   => 'firma',                   // optional, kleine Zusatzzeile
        // ],
    ],

    'search' => [
        'min_chars' => 2,
        'limit'     => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Favoriten (Seite nfmailer.favorites)
    |--------------------------------------------------------------------------
    | Persönliche Favoriten pflegt jeder User selbst. Gemeinsame Favoriten
    | (für alle) nur User mit einer dieser Rollen (spatie/laravel-permission);
    | ohne spatie darf jeder angemeldete User sie pflegen.
    */
    'favorites' => [
        'manage_roles' => ['Administrator', 'Partner'],
    ],

    // Eigentlicher Versandweg: null = Standard-Mailer aus config/mail.php.
    // Das Package schickt immer über den Mailer „nfmailer“, der diesen Weg umhüllt
    // (TLS-Pflicht, S/MIME). Nicht auf „nfmailer“ selbst setzen.
    'mailer' => env('NFMAILER_MAILER'),

    // SMTP nur mit Verschlüsselung (STARTTLS oder Port 465), sonst Abbruch statt Klartext
    'require_tls' => (bool) env('NFMAILER_REQUIRE_TLS', true),

    /*
    |--------------------------------------------------------------------------
    | S/MIME
    |--------------------------------------------------------------------------
    | Signatur: eigenes Zertifikat + privater Schlüssel (PEM). Ist beides gesetzt,
    | wird jede Mail signiert. Die Absenderadresse (MAIL_FROM_ADDRESS) muss im
    | Zertifikat stehen. Prüfen mit: php artisan nfmailer:check
    |
    | Verschlüsselung: nur möglich, wenn für ALLE Empfänger (An, CC, BCC) ein
    | Zertifikat hinterlegt ist (php artisan nfmailer:cert import datei.cer).
    */
    'smime' => [
        'certificate' => env('NFMAILER_SMIME_CERT'),         // z. B. /var/secure/smime/kanzlei.crt
        'key'         => env('NFMAILER_SMIME_KEY'),          // privater Schlüssel, Rechte 600, außerhalb des Webroots
        'passphrase'  => env('NFMAILER_SMIME_PASSPHRASE'),
        'extra_certs' => env('NFMAILER_SMIME_CHAIN'),        // Zwischenzertifikate der CA (optional)
        'encrypt'     => (bool) env('NFMAILER_SMIME_ENCRYPT', true),  // Option im Modal anbieten
        'certificates' => storage_path('app/private/nfmailer/certs'), // Zertifikate der Empfänger

        // Zertifikat des Absenders aus eingehenden signierten Mails übernehmen
        // (die Anwendung ruft dazu CertificateStore::importFromMail() beim Mailabruf auf)
        'auto_import' => (bool) env('NFMAILER_SMIME_AUTO_IMPORT', true),

        // Suche in öffentlichen LDAP-Verzeichnissen der CAs (nfmailer:cert lookup, Knopf im Modal).
        // Nur auf Wunsch: jede Suche schickt die Empfängeradresse an alle Verzeichnisse.
        // Benötigt die PHP-Erweiterung ldap.
        'directory' => [
            'enabled' => (bool) env('NFMAILER_SMIME_LOOKUP', true),
            'timeout' => 5,   // Sekunden je Verzeichnis
            'servers' => [
                ['name' => 'D-Trust',   'uri' => 'ldap://directory.d-trust.net',   'base' => 'c=de'],
                ['name' => 'DFN-PKI',   'uri' => 'ldap://ldap.pca.dfn.de',         'base' => 'o=DFN-Verein,c=DE'],
                ['name' => 'TeleSec',   'uri' => 'ldap://ldap.telesec.de',         'base' => 'c=de'],
                ['name' => 'SwissSign', 'uri' => 'ldap://directory.swisssign.net', 'base' => 'o=SwissSign AG,c=CH'],
            ],
        ],

        // Prüfung der Empfänger-Zertifikate bei der Zertifizierungsstelle
        'trust' => [
            // Vertrauenskette: 'require' = nur Zertifikate einer vertrauenswürdigen CA verwenden,
            // 'warn' = übernehmen und als „ungeprüft“ kennzeichnen, 'off' = nicht prüfen
            'chain'        => env('NFMAILER_SMIME_CHAIN_POLICY', 'warn'),
            'system_roots' => true,                                        // Zertifikatsspeicher des Systems nutzen
            'roots'        => storage_path('app/private/nfmailer/roots'),  // zusätzliche Wurzeln (nfmailer:cert trust)

            // Sperrstatus per OCSP (benötigt den Befehl „openssl“ und Internetzugang)
            'ocsp'              => (bool) env('NFMAILER_SMIME_OCSP', true),
            'check_before_send' => true,  // vor verschlüsseltem Versand erneut prüfen …
            'cache_hours'       => 24,    // … wenn die letzte Prüfung älter ist
            'timeout'           => 10,    // Sekunden je Abfrage
            'openssl'           => env('NFMAILER_OPENSSL', 'openssl'),
        ],
    ],

    // true = Versand über die Queue (Worker nötig)
    'queue' => (bool) env('NFMAILER_QUEUE', false),

    /*
    | Gesendete Mail ablegen: Schalter im Modal (Option 'archive' von nfmailer-open
    | überschreibt die Vorgabe). Nach dem Versand löst der mailer das Ereignis
    | Nfgarching\Mailer\Events\MailSent aus; die Anwendung legt die Mail dann ab.
    */
    'archive' => [
        'enabled' => (bool) env('NFMAILER_ARCHIVE', false),
        'default' => (bool) env('NFMAILER_ARCHIVE_DEFAULT', true),
        'label'   => env('NFMAILER_ARCHIVE_LABEL', 'Gesendete Mail ablegen'),
    ],

    'reply_to' => [
        'address' => env('NFMAILER_REPLY_TO'),
        'name'    => env('NFMAILER_REPLY_TO_NAME'),
    ],

    // Wird unter jede Mail gesetzt (leer = keine Signatur).
    // In der .env Zeilenumbrüche als \n schreiben, z. B.
    // NFMAILER_SIGNATURE="Kanzlei Muster\nMusterstraße 1\n12345 Musterstadt"
    'signature' => str_replace('\\n', "\n", (string) env('NFMAILER_SIGNATURE', '')),

    // Optionaler Gate-Name, z. B. 'send-mails' – sonst nur Login erforderlich
    'gate' => null,

    // Dateien, die beim Öffnen übergeben werden dürfen (Schutz vor Pfad-Manipulation)
    // allowed_prefixes: Liste für alle Disks oder [disk => Liste]; Disk ohne Eintrag = ganze Disk.
    // Einträge mit * sind Muster: Dokumente liegen unter {FILE_DIR_PREFIX}{Jahr}/akv/files|mails/…
    'attachments' => [
        'allowed_disks'    => ['local', 'data'],
        'allowed_prefixes' => [
            'local' => ['akten/', 'rechnungen/', '*akv/files/*', '*akv/mails/*'],
            // 'data': Dokumentablage der Kanzlei (Anhänge der Dokumente, z. B. 2026/akv/files/…)
        ],
    ],

    // Anhänge im Modal aus einer Quelle wählen (Button „Aus der Akte“, nur mit 'context').
    // Jede Quelle implementiert Nfgarching\Mailer\Contracts\AttachmentSource; die Dateien
    // durchlaufen dieselbe Prüfung wie oben ('attachments').
    'attachment_sources' => [
        [
            'class' => 'App\\Mail\\Attachments\\AkteDokumenteSource',
            'label' => 'Dokumente der Akte',
        ],
    ],

    // Im Modal hochgeladene Dateien
    'uploads' => [
        'disk'      => 'local',
        'directory' => 'nfmailer/uploads',
        'max_kb'    => 20480,
    ],

    // Download-Links statt Anhang
    'links' => [
        'default'            => false,  // Checkbox vorbelegt?
        'expires_days'       => 14,
        'pin_default'        => true,   // „Mit PIN schützen“ vorbelegt?
        'pin_attempts'       => 5,      // danach wird der Link gesperrt
        'max_downloads'      => null,   // z. B. 3; null = unbegrenzt bis Ablauf
        'notify_on_download' => true,   // Absender beim ersten Abruf benachrichtigen
    ],

    // php artisan nfmailer:prune (z. B. täglich im Scheduler)
    'prune' => [
        'links_after_days'   => 90,     // abgelaufene/gesperrte Links so lange nach Ablauf aufheben
        'uploads_after_days' => null,   // hochgeladene Dateien löschen; null = behalten (Nachweis)
    ],

    'route_prefix' => 'nfmailer',
];
