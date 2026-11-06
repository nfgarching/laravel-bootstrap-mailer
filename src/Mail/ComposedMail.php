<?php

namespace Nfgarching\Mailer\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Nfgarching\Mailer\Models\SentMail;
use Nfgarching\Mailer\Security\SecureTransport;
use Throwable;

/**
 * ShouldBeEncrypted: Beim Versand über die Queue liegt der Mailinhalt
 * verschlüsselt in der Jobs-Tabelle statt im Klartext.
 */
class ComposedMail extends Mailable implements ShouldBeEncrypted
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{disk: string, path: string, name: string}>  $files
     * @param  list<array{name: string, url: string, expires: ?string}>  $links
     */
    public function __construct(
        public string $mailSubject,
        public string $text,
        public array $files = [],
        public array $links = [],
        public ?string $signature = null,
        public bool $encrypt = false,
        public ?string $pinNote = null,
    ) {}

    /**
     * ID des Protokolleintrags (SentMail). Landet als öffentliche Eigenschaft in den
     * Daten des MessageSent-Ereignisses; RecordSentMail setzt damit nach dem
     * Versand durch den Queue-Worker Status und Message-ID.
     */
    public ?int $sentMailId = null;

    public function forLog(SentMail $log): static
    {
        $this->sentMailId = $log->id;

        return $this;
    }

    /** Vom Queue-Worker aufgerufen, wenn der Versand endgültig gescheitert ist */
    public function failed(Throwable $e): void
    {
        if ($this->sentMailId) {
            SentMail::whereKey($this->sentMailId)
                ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        }
    }

    public function envelope(): Envelope
    {
        $replyTo = config('nfmailer.reply_to.address')
            ? [new Address(config('nfmailer.reply_to.address'), config('nfmailer.reply_to.name'))]
            : [];

        return new Envelope(subject: $this->mailSubject, replyTo: $replyTo);
    }

    /** Steuer-Header für den SecureTransport (wird dort vor dem Versand entfernt) */
    public function headers(): Headers
    {
        return new Headers(text: $this->encrypt ? [SecureTransport::ENCRYPT_HEADER => '1'] : []);
    }

    public function content(): Content
    {
        return new Content(
            view: 'nfmailer::mail.composed',
            text: 'nfmailer::mail.composed-text',
        );
    }

    public function attachments(): array
    {
        return array_map(
            fn (array $f) => Attachment::fromStorageDisk($f['disk'], $f['path'])->as($f['name']),
            $this->files,
        );
    }
}
