<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminWebhookDto;
use App\Dto\Admin\AdminWebhookListDto;
use App\Repository\WebhookDeliveryRepository;
use App\Repository\WebhookRepository;

/**
 * @implements ProviderInterface<AdminWebhookListDto>
 */
final readonly class AdminWebhooksProvider implements ProviderInterface
{
    public function __construct(
        private WebhookRepository $webhookRepository,
        private WebhookDeliveryRepository $deliveryRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookListDto
    {
        $dto = new AdminWebhookListDto();
        foreach ($this->webhookRepository->findBy([], ['createdAt' => 'DESC']) as $webhook) {
            $dto->rows[] = AdminWebhookDto::fromWebhook(
                $webhook,
                $this->deliveryRepository->countReplayableFor($webhook),
            );
        }

        return $dto;
    }
}
