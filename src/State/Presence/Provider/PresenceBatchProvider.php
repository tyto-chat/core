<?php

declare(strict_types=1);

namespace App\State\Presence\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Presence\PresenceBatchDto;
use App\Dto\Presence\PresenceEntryDto;
use App\Service\Presence\PresenceServiceInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProviderInterface<PresenceBatchDto>
 */
final readonly class PresenceBatchProvider implements ProviderInterface
{
    private const int MAX_IDS = 200;

    public function __construct(
        private PresenceServiceInterface $presenceService,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PresenceBatchDto
    {
        $raw = $this->requestStack->getCurrentRequest()?->query->all('userIds') ?? [];

        $ids = [];
        foreach ($raw as $value) {
            if (!is_numeric($value)) {
                continue;
            }
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if (\count($ids) > self::MAX_IDS) {
            throw new UnprocessableEntityHttpException('Too many userIds (max 200).');
        }

        $presences = [];
        foreach ($this->presenceService->getBatch($ids) as $snapshot) {
            $presences[] = new PresenceEntryDto($snapshot->userId, $snapshot->state);
        }

        return new PresenceBatchDto($presences);
    }
}
