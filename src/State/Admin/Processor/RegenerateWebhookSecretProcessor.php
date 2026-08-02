<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminWebhookSecretDto;
use App\Enum\Admin\AdminAuditAction;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Webhook\WebhookServiceInterface;

/**
 * @implements ProcessorInterface<mixed, AdminWebhookSecretDto>
 */
final readonly class RegenerateWebhookSecretProcessor implements ProcessorInterface
{
    public function __construct(
        private WebhookServiceInterface $webhookService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookSecretDto
    {
        $webhook = $this->webhookService->getById((int) ($uriVariables['id'] ?? 0));

        $secret = $this->webhookService->regenerateSecret($webhook);

        $this->auditLogger->record(
            AdminAuditAction::WebhookRegenerateSecret,
            'webhook',
            $webhook->getId(),
            ['name' => $webhook->getName()],
        );

        return new AdminWebhookSecretDto($secret);
    }
}
