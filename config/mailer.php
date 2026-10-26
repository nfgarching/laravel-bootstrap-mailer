<?php

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
    ],

    // true = Versand über die Queue (Worker nötig)
    'queue' => (bool) env('NFMAILER_QUEUE', false),

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
    // allowed_prefixes: Liste für alle Disks oder [disk => Liste]; Disk ohne Eintrag = ganze Disk
    'attachments' => [
        'allowed_disks'    => ['local', 'data'],
        'allowed_prefixes' => [
            'local' => ['akten/', 'rechnungen/'],
            // 'data': Dokumentablage der Kanzlei (Anhänge der Dokumente, z. B. 2026/akv/files/…)
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
