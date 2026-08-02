<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UserGroup\AddGroupMemberDto;
use App\Entity\UserGroupMember;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProcessorInterface<AddGroupMemberDto, UserGroupMember>
 */
final readonly class AddGroupMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserServiceInterface $userService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserGroupMember
    {
        \assert($data instanceof AddGroupMemberDto);

        $community = $this->communityService->getByIdentifier((string) $uriVariables['community']);
        $group = $this->userGroupService->getByIdentifier((string) $uriVariables['identifier'], $community);
        $user = $this->userService->get((int) $data->userId);

        return $this->userGroupService->addMember($group, $user);
    }
}
