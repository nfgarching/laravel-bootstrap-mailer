<?php

namespace Nfgarching\Mailer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

/**
 * Download-Link für einen Anhang.
 *
 * Gespeichert wird nur der SHA-256-Hash des Tokens: Wer die Datenbank liest,
 * kann daraus keine gültigen Links bauen. Die URL gibt es nur direkt nach issue().
 */
class MailLink extends Model
{
    protected $table = 'sent_mails_links';

    protected $guarded = [];

    protected $hidden = ['token', 'pin_hash'];

    protected $casts = [
        'expires_at'         => 'datetime',
        'revoked_at'         => 'datetime',
        'last_downloaded_at' => 'datetime',
        'notified_at'        => 'datetime',
    ];

    /** Klartext-Token, nur im Speicher direkt nach issue() */
    protected ?string $plainToken = null;

    public static function issue(array $file, ?int $sentMailId = null, ?string $pin = null): self
    {
        $token = Str::random(48);
        $days = (int) config('nfmailer.links.expires_days', 14);
        $max = config('nfmailer.links.max_downloads');

        $link = self::create([
            'sent_mail_id'  => $sentMailId,
            'token'         => self::hashToken($token),
            'disk'          => $file['disk'],
            'path'          => $file['path'],
            'name'          => $file['name'],
            'pin_hash'      => $pin !== null ? Hash::make($pin) : null,
            'expires_at'    => $days > 0 ? now()->addDays($days) : null,
            'max_downloads' => $max ? (int) $max : null,
        ]);

        $link->plainToken = $token;

        return $link;
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token', self::hashToken($token))->first();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generatePin(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public function url(): string
    {
        if ($this->plainToken === null) {
            throw new LogicException('Die URL ist nur direkt nach MailLink::issue() verfügbar.');
        }

        return route('nfmailer.download', $this->plainToken);
    }

    public function hasPin(): bool
    {
        return $this->pin_hash !== null;
    }

    public function checkPin(string $pin): bool
    {
        return $this->hasPin() && Hash::check(preg_replace('/\D/', '', $pin), $this->pin_hash);
    }

    /** Grund, warum der Link nicht (mehr) nutzbar ist, oder null */
    public function unavailableReason(): ?string
    {
        return match (true) {
            $this->revoked_at !== null                     => 'Der Link wurde gesperrt.',
            $this->expires_at?->isPast() === true          => 'Der Link ist abgelaufen.',
            $this->max_downloads !== null
                && $this->download_count >= $this->max_downloads => 'Die Datei wurde bereits heruntergeladen. Der Link ist nicht mehr gültig.',
            default                                        => null,
        };
    }

    public function isExpired(): bool
    {
        return $this->unavailableReason() !== null;
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function sentMail(): BelongsTo
    {
        return $this->belongsTo(SentMail::class, 'sent_mail_id');
    }
}
