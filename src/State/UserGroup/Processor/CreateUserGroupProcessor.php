<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UserGroup\CreateUserGroupDto;
use App\Entity\UserGroup;
use App\Service\Community\CommunityServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProcessorInterface<CreateUserGroupDto, UserGroup>
 */
final readonly class CreateUserGroupProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserGroup
    {
        /** @var CreateUserGroupDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->userGroupService->new($community, $data);
    }
}
