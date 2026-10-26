<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sicherheits-Ausbau:
 *  - Protokoll verschlüsselt (Betreff, Text, Empfänger, Anhänge, PIN) → Spalten als TEXT
 *  - Download-Links: Token nur noch als SHA-256-Hash, PIN, Fehlversuche, Limit, Widerruf
 *  - Bestandsdaten werden umgestellt (vorhandene Links bleiben gültig)
 *
 * WICHTIG: Nach dieser Migration ist das Protokoll ohne APP_KEY nicht mehr lesbar.
 */
return new class extends Migration
{
    private const ENCRYPTED = ['subject', 'body', 'to', 'cc', 'bcc', 'attachments'];

    public function up(): void
    {
        Schema::table('sent_mails', function (Blueprint $t) {
            $t->text('subject')->change();
            $t->text('to')->change();
            $t->text('cc')->nullable()->change();
            $t->text('bcc')->nullable()->change();
            $t->text('attachments')->nullable()->change();
            $t->boolean('signed')->default(false)->after('as_links');
            $t->boolean('encrypted')->default(false)->after('signed');
            $t->text('pin')->nullable()->after('encrypted');
        });

        Schema::table('sent_mails_links', function (Blueprint $t) {
            $t->string('pin_hash')->nullable()->after('name');
            $t->unsignedTinyInteger('pin_failures')->default(0)->after('pin_hash');
            $t->unsignedInteger('max_downloads')->nullable()->after('expires_at');
            $t->timestamp('revoked_at')->nullable()->after('max_downloads');
            $t->timestamp('notified_at')->nullable()->after('last_downloaded_at');
        });

        // Bestandsdaten verschlüsseln (bereits verschlüsselte Werte überspringen)
        DB::table('sent_mails')->orderBy('id')->each(function ($row) {
            $update = [];
            foreach (self::ENCRYPTED as $col) {
                if ($row->{$col} !== null && ! $this->isEncrypted($row->{$col})) {
                    $update[$col] = Crypt::encryptString($row->{$col});
                }
            }
            if ($update) {
                DB::table('sent_mails')->where('id', $row->id)->update($update);
            }
        });

        // Klartext-Tokens (48 Zeichen) durch ihren Hash ersetzen – die versandten Links funktionieren weiter
        DB::table('sent_mails_links')->orderBy('id')->each(function ($row) {
            if (strlen($row->token) !== 64) {
                DB::table('sent_mails_links')->where('id', $row->id)->update(['token' => hash('sha256', $row->token)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('sent_mails')->orderBy('id')->each(function ($row) {
            $update = [];
            foreach (self::ENCRYPTED as $col) {
                if ($row->{$col} !== null && $this->isEncrypted($row->{$col})) {
                    $update[$col] = Crypt::decryptString($row->{$col});
                }
            }
            if ($update) {
                DB::table('sent_mails')->where('id', $row->id)->update($update);
            }
        });

        Schema::table('sent_mails_links', function (Blueprint $t) {
            $t->dropColumn(['pin_hash', 'pin_failures', 'max_downloads', 'revoked_at', 'notified_at']);
        });

        Schema::table('sent_mails', function (Blueprint $t) {
            $t->dropColumn(['signed', 'encrypted', 'pin']);
        });
        // Spaltentypen bleiben TEXT; gehashte Tokens lassen sich nicht zurückrechnen.
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
};
