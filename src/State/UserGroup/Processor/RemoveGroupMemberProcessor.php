<?php

declare(strict_types=1);

namespace App\State\UserGroup\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\UserGroup;
use App\Service\User\UserServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<UserGroup, null>
 */
final readonly class RemoveGroupMemberProcessor implements ProcessorInterface
{
    public function __construct(
        private UserServiceInterface $userService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var UserGroup $data */
        // {userId} has no uriVariables Link, so API Platform omits it from $uriVariables — read the raw route attribute
        $userId = (int) ($uriVariables['userId'] ?? ($context['request'] ?? null)?->attributes->get('userId', 0));
        if (0 === $userId) {
            throw new UnprocessableEntityHttpException('Missing userId.');
        }

        $user = $this->userService->get($userId);
        $this->userGroupService->removeMember($data, $user);

        return null;
    }
}
