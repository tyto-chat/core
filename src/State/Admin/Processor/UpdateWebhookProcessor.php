<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminWebhookDto;
use App\Enum\Admin\AdminAuditAction;
use App\Repository\WebhookDeliveryRepository;
use App\Service\Admin\AdminAuditLoggerInterface;
use App\Service\Webhook\WebhookServiceInterface;
use App\Utils\JsonRequestBody;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Raw-body read, not a typed DTO — `filters: null` (clear) must stay distinguishable from an absent key.
 *
 * @implements ProcessorInterface<mixed, AdminWebhookDto>
 */
final readonly class UpdateWebhookProcessor implements ProcessorInterface
{
    public function __construct(
        private WebhookServiceInterface $webhookService,
        private WebhookDeliveryRepository $deliveryRepository,
        private AdminAuditLoggerInterface $auditLogger,
        private RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminWebhookDto
    {
        $webhook = $this->webhookService->getById((int) ($uriVariables['id'] ?? 0));

        $payload = JsonRequestBody::decode($this->requestStack->getCurrentRequest());

        $changes = [];
        if (array_key_exists('name', $payload)) {
            $changes['name'] = $this->asString($payload, 'name', 1, 120);
        }
        if (array_key_exists('url', $payload)) {
            $changes['url'] = $this->asString($payload, 'url', 1, 2048);
        }
        if (array_key_exists('filters', $payload)) {
            $filters = $payload['filters'];
            if (null !== $filters && !is_array($filters)) {
                throw new BadRequestHttpException('Field "filters" must be an object or null.');
            }
            $changes['filters'] = $filters;
        }
        if (array_key_exists('isActive', $payload)) {
            if (!is_bool($payload['isActive'])) {
                throw new BadRequestHttpException('Field "isActive" must be a boolean.');
            }
            $changes['isActive'] = $payload['isActive'];
        }

        $replayPending = isset($payload['replayPending']) && true === $payload['replayPending'];

        $updated = $this->webhookService->update($webhook, $changes, $replayPending);

        $this->auditLogger->record(
            AdminAuditAction::WebhookUpdate,
            'webhook',
            $updated->getId(),
            ['changes' => $changes, 'replayPending' => $replayPending],
        );

        return AdminWebhookDto::fromWebhook(
            $updated,
            $this->deliveryRepository->countReplayableFor($updated),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function asString(array $payload, string $key, int $minLength, int $maxLength): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be a string.', $key));
        }
        $trimmed = trim($value);
        $length = mb_strlen($trimmed);
        if ($length < $minLength || $length > $maxLength) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be %d–%d characters.', $key, $minLength, $maxLength));
        }

        return $trimmed;
    }
}
