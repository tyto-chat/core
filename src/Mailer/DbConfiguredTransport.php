<?php

declare(strict_types=1);

namespace App\Mailer;

use App\Enum\Settings\SmtpEncryption;
use App\Service\Security\SecretBoxInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

final class DbConfiguredTransport implements TransportInterface
{
    private ?TransportInterface $built = null;
    private ?string $builtStamp = null;

    public function __construct(
        private readonly TransportInterface $inner,
        private readonly SettingsServiceInterface $settings,
        private readonly SecretBoxInterface $secretBox,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        return $this->resolve()->send($message, $envelope);
    }

    public function __toString(): string
    {
        return (string) $this->resolve();
    }

    private function resolve(): TransportInterface
    {
        return $this->buildTransport() ?? $this->inner;
    }

    public function buildTransport(): ?TransportInterface
    {
        if (!$this->settings->isSmtpConfigured()) {
            return null;
        }
        $stamp = $this->settings->lastUpdate()['at']?->format('U') ?? '0';
        if (null !== $this->built && $this->builtStamp === $stamp) {
            return $this->built;
        }

        $tls = match ($this->settings->get(Settings::smtpEncryption())) {
            SmtpEncryption::Ssl => true,
            SmtpEncryption::None => false,
            default => null, // tls => STARTTLS auto-negotiation
        };
        // Port 0 = EsmtpTransport auto-selects (465 for SSL, 25 otherwise).
        // The dispatcher is required — a dispatcherless transport skips MessageEvent From/envelope stamping and sends From-less mail SMTP servers reject.
        $transport = new EsmtpTransport((string) $this->settings->get(Settings::smtpHost()), $this->settings->get(Settings::smtpPort()) ?? 0, $tls, $this->dispatcher);
        $username = $this->settings->get(Settings::smtpUsername());
        if (null !== $username) {
            $transport->setUsername($username);
        }
        $enc = $this->settings->get(Settings::smtpPassword());
        if (null !== $enc) {
            $transport->setPassword($this->secretBox->decrypt($enc) ?? '');
        }

        $this->built = $transport;
        $this->builtStamp = $stamp;

        return $transport;
    }
}
