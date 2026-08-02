<?php

declare(strict_types=1);

namespace App\State\UserGroup\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\GroupChannelPermission;
use App\Service\Community\CommunityServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProviderInterface<GroupChannelPermission>
 */
final readonly class GroupChannelPermissionsProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $identifier = $uriVariables['identifier'];
        $group = $this->userGroupService->getByIdentifier((string) $identifier, $community);

        return $this->userGroupService->getChannelPermissions($group);
    }
}
