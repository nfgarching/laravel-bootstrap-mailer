<?php

namespace Nfgarching\Mailer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MailLink extends Model
{
    protected $table = 'nf_mail_links';

    protected $guarded = [];

    protected $casts = [
        'expires_at'         => 'datetime',
        'last_downloaded_at' => 'datetime',
    ];

    public static function issue(array $file, ?int $sentMailId = null): self
    {
        $days = (int) config('nfmailer.links.expires_days', 14);

        return self::create([
            'sent_mail_id' => $sentMailId,
            'token'        => Str::random(48),
            'disk'         => $file['disk'],
            'path'         => $file['path'],
            'name'         => $file['name'],
            'expires_at'   => $days > 0 ? now()->addDays($days) : null,
        ]);
    }

    public function url(): string
    {
        return route('nfmailer.download', $this->token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function sentMail(): BelongsTo
    {
        return $this->belongsTo(SentMail::class, 'sent_mail_id');
    }
}
