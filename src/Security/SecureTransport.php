<?php

namespace Nfgarching\Mailer\Security;

use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Crypto\SMimeEncrypter;
use Symfony\Component\Mime\Crypto\SMimeSigner;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * Hülle um den eigentlichen Transport (SMTP, Google …):
 *
 *  - erzwingt TLS auf der SMTP-Verbindung (kein Rückfall auf Klartext)
 *  - signiert jede Mail per S/MIME, wenn ein Zertifikat konfiguriert ist
 *  - verschlüsselt per S/MIME, wenn die Mail den Header X-Nfmailer-Encrypt trägt
 *    (nur möglich, wenn für alle Empfänger ein Zertifikat vorliegt – sonst Abbruch,
 *    niemals stiller Rückfall auf unverschlüsselt)
 */
class SecureTransport implements TransportInterface
{
    public const ENCRYPT_HEADER = 'X-Nfmailer-Encrypt';

    /**
     * @param array{certificate?: ?string, key?: ?string, passphrase?: ?string, extra_certs?: ?string} $signing
     */
    public function __construct(
        protected TransportInterface $inner,
        protected array $signing = [],
        protected ?CertificateStore $certificates = null,
        bool $requireTls = true,
    ) {
        if ($requireTls && $inner instanceof EsmtpTransport) {
            $inner->setRequireTls(true);
        }
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! $message instanceof Message) {
            return $this->inner->send($message, $envelope);
        }

        $headers = $message->getHeaders();
        $encrypt = $headers->has(self::ENCRYPT_HEADER);
        $headers->remove(self::ENCRYPT_HEADER);

        if (! $encrypt && ! $this->signs()) {
            return $this->inner->send($message, $envelope);
        }

        $envelope ??= Envelope::create($message);

        // Message-ID vorab setzen, damit protokollierte und versendete ID übereinstimmen
        if (! $headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', $message->generateMessageId());
        }

        $secured = $message;

        if ($this->signs()) {
            $secured = $this->signer()->sign($secured);
        }

        if ($encrypt) {
            $secured = $this->encrypter($envelope)->encrypt($secured);
        }

        $sent = $this->inner->send($secured, $envelope);

        // Laravel erwartet im SentMessage die ursprüngliche Email (Events, Serialisierung)
        if ($sent !== null) {
            $result = new SentMessage($message, $sent->getEnvelope());
            $result->setMessageId($sent->getMessageId());
            $result->appendDebug($sent->getDebug());

            return $result;
        }

        return null;
    }

    public function signs(): bool
    {
        return ! empty($this->signing['certificate']) && ! empty($this->signing['key']);
    }

    protected function signer(): SMimeSigner
    {
        return new SMimeSigner(
            $this->signing['certificate'],
            $this->signing['key'],
            ($this->signing['passphrase'] ?? null) ?: null,
            ($this->signing['extra_certs'] ?? null) ?: null,
        );
    }

    protected function encrypter(Envelope $envelope): SMimeEncrypter
    {
        if (! $this->certificates) {
            throw new RuntimeException('S/MIME-Verschlüsselung ist nicht konfiguriert.');
        }

        $paths = [];
        $missing = [];

        foreach ($envelope->getRecipients() as $address) {
            $email = $address->getAddress();

            // ggf. Sperrstatus beim Aussteller auffrischen (smime.trust.check_before_send)
            $this->certificates->ensureFresh($email);

            $path = $this->certificates->path($email);
            $path ? $paths[] = $path : $missing[] = $email . ' (' . $this->certificates->unusableReason($email) . ')';
        }

        if ($missing) {
            throw new RuntimeException('Verschlüsselung nicht möglich: ' . implode(', ', $missing)
                . '. Die Mail wurde nicht versendet.');
        }

        // Eigenes Zertifikat ergänzen, damit die Kopie in „Gesendet“ lesbar bleibt
        if ($this->signs()) {
            $paths[] = $this->signing['certificate'];
        }

        return new SMimeEncrypter(array_values(array_unique($paths)));
    }

    public function __toString(): string
    {
        return 'nfmailer+' . $this->inner;
    }
}
