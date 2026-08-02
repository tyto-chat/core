<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminWebhookTestResultDto;
use App\Enum\Admin\AdminAuditAction;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Webhook\WebhookServiceInterface;

/**
 * @implements ProcessorInterface<mixed, AdminWebhookTestResultDto>
 */
final readonly class TestWebhookProcessor implements ProcessorInterface
{
    public function __construct(
        private WebhookServiceInterface $webhookService,
        private AdminAuditLoggerInterface $auditLogger,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookTestResultDto
    {
        $webhook = $this->webhookService->getById((int) ($uriVariables['id'] ?? 0));

        $result = $this->webhookService->sendTest($webhook);

        $this->auditLogger->record(
            AdminAuditAction::WebhookTest,
            'webhook',
            $webhook->getId(),
            ['triggerKey' => $webhook->getTriggerKey()],
        );

        return new AdminWebhookTestResultDto($result['deliveryId'], $result['status']);
    }
}
