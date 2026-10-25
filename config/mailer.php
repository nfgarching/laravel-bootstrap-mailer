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

    // null = Standard-Mailer aus config/mail.php
    'mailer' => env('NFMAILER_MAILER'),

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
        'default'      => false,  // Checkbox vorbelegt?
        'expires_days' => 14,
    ],

    'route_prefix' => 'nfmailer',
];
