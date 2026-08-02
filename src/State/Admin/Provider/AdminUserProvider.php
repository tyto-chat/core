<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminUserDetailDto;
use App\Service\Admin\AdminUserServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProviderInterface<AdminUserDetailDto>
 */
final readonly class AdminUserProvider implements ProviderInterface
{
    public function __construct(
        private UserServiceInterface $userService,
        private AdminUserServiceInterface $adminUserService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminUserDetailDto
    {
        return $this->adminUserService->detail(
            $this->userService->get((int) ($uriVariables['id'] ?? 0)),
        );
    }
}
