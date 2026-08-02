<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserGroup;
use App\Service\UserGroup\UserGroupServiceInterface;

/**
 * @implements ProcessorInterface<UserGroup, void>
 */
final readonly class DeleteUserGroupProcessor implements ProcessorInterface
{
    public function __construct(
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->userGroupService->delete($data);
    }
}
