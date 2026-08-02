<?php

declare(strict_types=1);

namespace App\State\CommunityMember\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProviderInterface<\App\Entity\CommunityMember>
 */
final readonly class CommunityMembersProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->communityService->getMembers($community);
    }
}
