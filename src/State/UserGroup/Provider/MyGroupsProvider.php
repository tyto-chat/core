<?php

declare(strict_types=1);

namespace App\State\UserGroup\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MyGroups;
use App\Dto\UserGroup\MyGroupDto;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProviderInterface<MyGroups>
 */
final readonly class MyGroupsProvider implements ProviderInterface
{
    public function __construct(
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MyGroups
    {
        $resource = new MyGroups();
        foreach ($this->userGroupService->getMyGroups() as $row) {
            $group = $row['group'];
            $community = $group->getCommunity();
            $resource->items[] = new MyGroupDto(
                (string) $group->getIdentifier(),
                $group->getName(),
                $group->getIcon(),
                $group->getColor(),
                (string) $community->getIdentifier(),
                $community->getName(),
                $row['memberCount'],
                $row['isOwner'],
            );
        }

        return $resource;
    }
}
