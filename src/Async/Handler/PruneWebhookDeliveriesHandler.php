<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\PruneWebhookDeliveriesMessage;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PruneWebhookDeliveriesHandler
{
    public function __construct(
        private readonly WebhookDeliveryRepository $deliveryRepository,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    public function __invoke(PruneWebhookDeliveriesMessage $message): void
    {
        $retentionDays = $this->settings->get(Settings::webhookLogRetentionDays());
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $retentionDays));
        $this->deliveryRepository->deleteOlderThan($cutoff);
    }
}
