<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UserGroup\TransferGroupOwnershipDto;
use App\Entity\UserGroup;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProcessorInterface<TransferGroupOwnershipDto, UserGroup>
 */
final readonly class TransferGroupOwnershipProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private UserServiceInterface $userService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserGroup
    {
        \assert($data instanceof TransferGroupOwnershipDto);

        $community = $this->communityService->getByIdentifier((string) $uriVariables['community']);
        $group = $this->userGroupService->getByIdentifier((string) $uriVariables['identifier'], $community);
        $newOwner = $this->userService->get((int) $data->userId);

        return $this->userGroupService->transferOwnership($group, $newOwner);
    }
}
