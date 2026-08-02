<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProviderInterface<User>
 */
final readonly class UserProvider implements ProviderInterface
{
    public function __construct(private UserServiceInterface $userService)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): User
    {
        return $this->userService->get((int) $uriVariables['id']);
    }
}
