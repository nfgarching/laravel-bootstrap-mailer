<?php

namespace Nfgarching\Mailer\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SentMail extends Model
{
    protected $table = 'sent_mails';

    protected $guarded = [];

    protected $casts = [
        'to'          => 'array',
        'cc'          => 'array',
        'bcc'         => 'array',
        'attachments' => 'array',
        'context'     => 'array',
        'as_links'    => 'boolean',
        'sent_at'     => 'datetime',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(MailLink::class, 'sent_mail_id');
    }

    /** SentMail::forContext(['akte' => 123])->latest()->get() */
    public function scopeForContext(Builder $q, array $context): Builder
    {
        return $q->where('context_key', self::contextKey($context));
    }

    public static function contextKey(array $context): ?string
    {
        if (! $context) {
            return null;
        }

        $key = array_key_first($context);

        return is_scalar($context[$key]) ? $key . ':' . $context[$key] : null;
    }
}
