<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entwürfe, zeitversetzter Versand und PDF-Kopie (Planungsskizze, Phase 0):
 *
 *  status       zusätzlich 'draft' (Entwurf); später 'scheduled', 'sending', 'cancelled'
 *  options      Schalter des Modals (Links, PIN, Verschlüsselung, Ablage, Signatur …)
 *  version      Zähler gegen gleichzeitiges Bearbeiten eines Entwurfs in zwei Fenstern
 *  scheduled_at, claimed_at, attempts   zeitversetzter Versand (Phase 2)
 *  pdf_disk, pdf_path                   PDF-Kopie der versandten Mail (Phase 3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_mails', function (Blueprint $t) {
            $t->json('options')->nullable()->after('archive');
            $t->unsignedInteger('version')->default(1)->after('options');
            $t->timestamp('scheduled_at')->nullable()->after('version');
            $t->timestamp('claimed_at')->nullable()->after('scheduled_at');
            $t->unsignedTinyInteger('attempts')->default(0)->after('claimed_at');
            $t->string('pdf_disk')->nullable()->after('attempts');
            $t->string('pdf_path')->nullable()->after('pdf_disk');

            $t->index(['status', 'scheduled_at']);
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sent_mails', function (Blueprint $t) {
            $t->dropIndex(['status', 'scheduled_at']);
            $t->dropIndex(['user_id', 'status']);
            $t->dropColumn(['options', 'version', 'scheduled_at', 'claimed_at', 'attempts', 'pdf_disk', 'pdf_path']);
        });
    }
};
