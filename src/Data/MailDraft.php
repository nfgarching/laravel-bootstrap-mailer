<?php

namespace Nfgarching\Mailer\Data;

use Nfgarching\Mailer\Models\SentMail;

/**
 * Inhalt einer Mail, wie ihn das Modal hält: Empfänger, Betreff, Text, Kontext,
 * Anhänge (mit Auswahl-Flag 'active') und die Schalter.
 *
 * Wird als Entwurf in sent_mails gespeichert (MailDraft::toAttributes) und daraus
 * wieder geladen (MailDraft::fromLog); der MailDispatcher versendet einen solchen
 * Datensatz ohne das Modal.
 *
 * Schalter (options) – gleiche Schlüssel wie die Optionen von 'nfmailer-open':
 *   links, pin, pin_by_mail, encrypt, archive, signature, attachments_label, show_copies
 */
final class MailDraft
{
    public const OPTION_DEFAULTS = [
        'links' => false,
        'pin' => true,
        'pin_by_mail' => false,
        'encrypt' => true,
        'archive' => false,
        'signature' => true,
        'attachments_label' => '',
        'show_copies' => false,
    ];

    /**
     * @param  list<array{email: string, name: ?string, hint?: ?string, source?: ?string}>  $to
     * @param  list<array{disk: string, path: string, name: string, size?: int, origin?: string, active?: bool}>  $attachments
     */
    public function __construct(
        public array $to = [],
        public array $cc = [],
        public array $bcc = [],
        public string $subject = '',
        public string $body = '',
        public array $context = [],
        public array $attachments = [],
        public array $options = [],
    ) {
        $this->options = array_merge(self::OPTION_DEFAULTS, array_intersect_key($options, self::OPTION_DEFAULTS));
    }

    public static function fromLog(SentMail $log): self
    {
        return new self(
            to: (array) ($log->to ?? []),
            cc: (array) ($log->cc ?? []),
            bcc: (array) ($log->bcc ?? []),
            subject: (string) $log->subject,
            body: (string) $log->body,
            context: (array) ($log->context ?? []),
            attachments: (array) ($log->attachments ?? []),
            options: (array) ($log->options ?? []) + self::legacyOptions($log),
        );
    }

    /** Ältere Protokolleinträge ohne 'options': Schalter aus den Einzelspalten ableiten */
    protected static function legacyOptions(SentMail $log): array
    {
        return [
            'links' => (bool) $log->as_links,
            'encrypt' => (bool) $log->encrypted,
            'archive' => (bool) $log->archive,
        ];
    }

    public function option(string $key): mixed
    {
        return $this->options[$key] ?? self::OPTION_DEFAULTS[$key] ?? null;
    }

    /** Attribute für sent_mails (Betreff, Text, Empfänger, Anhänge werden über die Casts verschlüsselt) */
    public function toAttributes(): array
    {
        return [
            'subject' => $this->subject,
            'body' => $this->body,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'attachments' => $this->attachments,
            'context' => $this->context,
            'context_key' => SentMail::contextKey($this->context),
            'options' => $this->options,
        ];
    }

    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'subject' => $this->subject,
            'body' => $this->body,
            'context' => $this->context,
            'attachments' => $this->attachments,
            'options' => $this->options,
        ];
    }

    /** Fingerabdruck des Inhalts – Grundlage für „ungespeicherte Änderungen“ */
    public function hash(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Alle Empfänger (An, CC, BCC) */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    /** Anhänge, die mitgesendet werden – ohne das Auswahl-Flag */
    public function activeAttachments(): array
    {
        return array_values(array_map(
            fn (array $a) => array_diff_key($a, ['active' => true]),
            array_filter($this->attachments, fn (array $a) => $a['active'] ?? true),
        ));
    }
}
