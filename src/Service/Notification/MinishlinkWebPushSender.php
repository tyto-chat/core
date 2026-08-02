<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\PushSubscription as PushSubscriptionEntity;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Webhook\WebhookUrlGuard;
use App\Settings\Settings;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class MinishlinkWebPushSender implements WebPushSenderInterface
{
    private ?WebPush $webPush = null;

    public function __construct(
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
        private readonly WebhookUrlGuard $urlGuard,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return '' !== $this->vapidPublicKey && '' !== $this->vapidPrivateKey;
    }

    #[\Override]
    public function send(PushSubscriptionEntity $subscription, array $payload): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        $allowInternal = $this->settings->get(Settings::webhookAllowInternalUrls());
        if (!$this->urlGuard->isAllowed($subscription->getEndpoint(), $allowInternal)) {
            return false;
        }

        $report = $this->client()->sendOneNotification(
            Subscription::create([
                'endpoint' => $subscription->getEndpoint(),
                'keys' => [
                    'p256dh' => $subscription->getP256dh(),
                    'auth' => $subscription->getAuthToken(),
                ],
            ]),
            json_encode($payload, \JSON_THROW_ON_ERROR),
        );

        if ($report->isSubscriptionExpired()) {
            return false;
        }

        return true;
    }

    private function client(): WebPush
    {
        return $this->webPush ??= new WebPush([
            'VAPID' => [
                'subject' => $this->vapidSubject,
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ]);
    }
}
