<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminUserDetailDto;
use App\Service\Admin\AdminUserServiceInterface;

/**
 * @implements ProcessorInterface<mixed, AdminUserDetailDto>
 */
final readonly class DisableAdminUserTwoFactorProcessor implements ProcessorInterface
{
    public function __construct(
        private AdminUserServiceInterface $adminUserService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminUserDetailDto
    {
        $user = $this->adminUserService->disableTwoFactor((int) ($uriVariables['id'] ?? 0));

        return $this->adminUserService->detail($user);
    }
}
