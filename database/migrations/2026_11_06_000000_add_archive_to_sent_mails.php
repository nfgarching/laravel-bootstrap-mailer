<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wunsch „Gesendete Mail ablegen“ je Versand (Schalter im Modal).
 * Wird erst nach dem Versand ausgewertet – beim Queue-Versand im Worker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_mails', function (Blueprint $t) {
            $t->boolean('archive')->default(false)->after('encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('sent_mails', function (Blueprint $t) {
            $t->dropColumn('archive');
        });
    }
};
