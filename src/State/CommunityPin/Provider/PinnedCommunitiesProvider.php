<?php

declare(strict_types=1);

namespace App\State\CommunityPin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CommunityPin;
use App\Service\Community\CommunityPinServiceInterface;

/**
 * @implements ProviderInterface<CommunityPin>
 */
final readonly class PinnedCommunitiesProvider implements ProviderInterface
{
    public function __construct(
        private CommunityPinServiceInterface $pinService,
    ) {
    }

    /**
     * @return iterable<CommunityPin>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        return $this->pinService->listForCurrentUser();
    }
}
