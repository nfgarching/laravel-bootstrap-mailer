<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Favoriten des Empfänger-Modals: user_id null = für alle (z. B. Funktionsadressen
     * wie Buchhaltung), sonst persönlicher Favorit dieses Users.
     */
    public function up(): void
    {
        Schema::create('mail_favorites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('email');
            $t->string('name')->nullable();
            $t->string('hint')->nullable();      // Zusatzzeile, z. B. "Funktionsadresse"
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();

            $t->unique(['user_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_favorites');
    }
};
