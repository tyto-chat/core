<?php

declare(strict_types=1);

namespace App\State\Appeal\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Appeal;
use App\Enum\Moderation\AppealStatus;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\AppealServiceInterface;

/**
 * @implements ProviderInterface<Appeal>
 */
final readonly class CommunityAppealsProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private AppealServiceInterface $appealService,
    ) {
    }

    /** @return list<Appeal> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $context['request'] ?? null;
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $status = AppealStatus::tryFrom((string) ($request?->query->get('status') ?? ''));
        $page = (int) ($request?->query->get('page') ?? 1);

        return $this->appealService->listForCommunity($community, $status, $page);
    }
}
