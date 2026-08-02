<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Enum\Admin\AdminAuditAction;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Webhook\WebhookServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteWebhookProcessor implements ProcessorInterface
{
    public function __construct(
        private WebhookServiceInterface $webhookService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $id = (int) ($uriVariables['id'] ?? 0);
        $webhook = $this->webhookService->getById($id);

        $name = $webhook->getName();
        $this->webhookService->delete($webhook);

        $this->auditLogger->record(AdminAuditAction::WebhookDelete, 'webhook', null, ['id' => $id, 'name' => $name]);

        return null;
    }
}
