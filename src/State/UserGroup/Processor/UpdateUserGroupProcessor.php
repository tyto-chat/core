<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\UserGroup\UpdateUserGroupDto;
use App\Entity\UserGroup;
use App\Service\User\UserServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<UpdateUserGroupDto, UserGroup>
 */
final readonly class UpdateUserGroupProcessor implements ProcessorInterface
{
    public function __construct(
        private UserGroupServiceInterface $userGroupService,
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserGroup
    {
        /** @var UpdateUserGroupDto $data */
        /** @var UserGroup $group */
        $group = $context['read_data'];

        $owner = null;
        if ($data->isProvided('ownerId') && null !== $data->ownerId) {
            try {
                $owner = $this->userService->get($data->ownerId);
            } catch (\Exception) {
                throw new UnprocessableEntityHttpException(sprintf('User %d not found.', $data->ownerId));
            }
        }

        try {
            return $this->userGroupService->update($group, $data, $owner);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }
}
