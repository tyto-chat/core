<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\ChangeRolesDto;
use App\Entity\User;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<ChangeRolesDto, User>
 */
final readonly class ChangeRolesProcessor implements ProcessorInterface
{
    public function __construct(
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        /** @var ChangeRolesDto $data */
        /** @var User $user */
        $user = $context['read_data'];

        return $this->userService->updateRoles($user, $data->roles);
    }
}
