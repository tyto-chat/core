<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminWebhookDto;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Webhook\WebhookServiceInterface;

/**
 * @implements ProviderInterface<AdminWebhookDto>
 */
final readonly class AdminWebhookProvider implements ProviderInterface
{
    public function __construct(
        private WebhookDeliveryRepository $deliveryRepository,
        private WebhookServiceInterface $webhookService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookDto
    {
        $webhook = $this->webhookService->getById((int) ($uriVariables['id'] ?? 0));

        return AdminWebhookDto::fromWebhook(
            $webhook,
            $this->deliveryRepository->countReplayableFor($webhook),
        );
    }
}
