<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

// Priority -1000 must stay below Symfony's envelope listener — forces MAIL FROM to the DB-configured sender so SPF/DMARC align.
#[AsEventListener(event: MessageEvent::class, priority: -1000)]
final readonly class ConfiguredSenderListener
{
    public function __construct(
        private SettingsServiceInterface $settings,
    ) {
    }

    public function __invoke(MessageEvent $event): void
    {
        $fromEmail = $this->settings->get(Settings::smtpFromEmail());
        if (!$this->settings->isSmtpConfigured() || null === $fromEmail || '' === $fromEmail) {
            return;
        }

        $address = new Address($fromEmail, $this->settings->get(Settings::smtpFromName()) ?? '');

        $message = $event->getMessage();
        if ($message instanceof Email && [] === $message->getFrom()) {
            $message->from($address);
        }

        $event->getEnvelope()->setSender($address);
    }
}
