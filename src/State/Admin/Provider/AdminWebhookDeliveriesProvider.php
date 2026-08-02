<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminWebhookDeliveryDto;
use App\Dto\Admin\AdminWebhookDeliveryPageDto;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Webhook\WebhookServiceInterface;
use App\Utils\PaginationParams;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<AdminWebhookDeliveryPageDto>
 */
final readonly class AdminWebhookDeliveriesProvider implements ProviderInterface
{
    public function __construct(
        private WebhookDeliveryRepository $deliveryRepository,
        private WebhookServiceInterface $webhookService,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookDeliveryPageDto
    {
        $webhook = $this->webhookService->getById((int) ($uriVariables['id'] ?? 0));

        $pagination = PaginationParams::fromRequest($this->requestStack->getCurrentRequest());

        $dto = new AdminWebhookDeliveryPageDto();
        $dto->page = $pagination->page;
        $dto->perPage = $pagination->perPage;
        $dto->replayableCount = $this->deliveryRepository->countReplayableFor($webhook);
        foreach ($this->deliveryRepository->findForWebhookPaginated($webhook, $pagination->page, $pagination->perPage) as $delivery) {
            $dto->rows[] = AdminWebhookDeliveryDto::fromDelivery($delivery);
        }

        return $dto;
    }
}
