<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nf_sent_mails', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('status', 20)->default('pending');   // pending | sent | queued | failed
            $t->string('mailer')->nullable();
            $t->string('subject');
            $t->longText('body');
            $t->json('to');
            $t->json('cc')->nullable();
            $t->json('bcc')->nullable();
            $t->json('attachments')->nullable();
            $t->boolean('as_links')->default(false);
            $t->json('context')->nullable();                  // z. B. {"akte": 123}
            $t->string('context_key')->nullable()->index();   // z. B. "akte:123" für schnelle Abfrage
            $t->string('message_id')->nullable();
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        Schema::create('nf_mail_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sent_mail_id')->nullable()->index();
            $t->string('token', 64)->unique();
            $t->string('disk');
            $t->string('path');
            $t->string('name');
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('download_count')->default(0);
            $t->timestamp('last_downloaded_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nf_mail_links');
        Schema::dropIfExists('nf_sent_mails');
    }
};
