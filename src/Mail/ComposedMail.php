<?php

namespace Nfgarching\Mailer\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ComposedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param list<array{disk: string, path: string, name: string}> $files
     * @param list<array{name: string, url: string, expires: ?string}> $links
     */
    public function __construct(
        public string $mailSubject,
        public string $text,
        public array $files = [],
        public array $links = [],
        public ?string $signature = null,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = config('nfmailer.reply_to.address')
            ? [new Address(config('nfmailer.reply_to.address'), config('nfmailer.reply_to.name'))]
            : [];

        return new Envelope(subject: $this->mailSubject, replyTo: $replyTo);
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
