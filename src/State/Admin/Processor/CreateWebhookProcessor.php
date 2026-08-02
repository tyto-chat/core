<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminWebhookWithSecretDto;
use App\Dto\Admin\CreateWebhookDto;
use App\Entity\Webhook;
use App\Enum\Admin\AdminAuditAction;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Webhook\WebhookServiceInterface;

/**
 * @implements ProcessorInterface<CreateWebhookDto, AdminWebhookWithSecretDto>
 */
final readonly class CreateWebhookProcessor implements ProcessorInterface
{
    public function __construct(
        private WebhookServiceInterface $webhookService,
        private WebhookDeliveryRepository $deliveryRepository,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookWithSecretDto
    {
        \assert($data instanceof CreateWebhookDto);

        $result = $this->webhookService->create($data->name, $data->url, $data->triggerKey, $data->filters);
        $webhook = $result['webhook'];
        \assert($webhook instanceof Webhook);
        $secret = (string) $result['secret'];

        $this->auditLogger->record(
            AdminAuditAction::WebhookCreate,
            'webhook',
            $webhook->getId(),
            ['name' => $webhook->getName(), 'triggerKey' => $webhook->getTriggerKey()],
        );

        return AdminWebhookWithSecretDto::fromWebhookWithSecret(
            $webhook,
            $this->deliveryRepository->countReplayableFor($webhook),
            $secret,
        );
    }
}
