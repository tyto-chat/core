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
final readonly class UserGroupProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserGroup
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $identifier = $uriVariables['identifier'];

        return $this->userGroupService->getByIdentifier((string) $identifier, $community);
    }
}
