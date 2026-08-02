<?php

declare(strict_types=1);

namespace App\State\UserGroup\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\UserGroup;
use App\Service\Community\CommunityServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProviderInterface<UserGroup>
 */
final readonly class UserGroupsProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->userGroupService->getAllForCommunity($community);
    }
}
