<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminWebhookReplayResultDto;
use App\Enum\Admin\AdminAuditAction;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Webhook\WebhookServiceInterface;

/**
 * @implements ProcessorInterface<mixed, AdminWebhookReplayResultDto>
 */
final readonly class ReplayWebhookProcessor implements ProcessorInterface
{
    public function __construct(
        private WebhookServiceInterface $webhookService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookReplayResultDto
    {
        $webhook = $this->webhookService->getById((int) ($uriVariables['id'] ?? 0));

        $count = $this->webhookService->replay($webhook);

        $this->auditLogger->record(AdminAuditAction::WebhookReplay, 'webhook', $webhook->getId(), ['count' => $count]);

        return new AdminWebhookReplayResultDto($count);
    }
}
