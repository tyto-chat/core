<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\AdminUserDetailDto;
use App\Dto\Admin\AdminUserPatchDto;
use App\Service\Admin\AdminUserServiceInterface;

/**
 * @implements ProcessorInterface<AdminUserPatchDto, AdminUserDetailDto>
 */
final readonly class PatchAdminUserProcessor implements ProcessorInterface
{
    public function __construct(
        private AdminUserServiceInterface $adminUserService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminUserDetailDto
    {
        \assert($data instanceof AdminUserPatchDto);

        $user = $this->adminUserService->patch((int) ($uriVariables['id'] ?? 0), $data);

        return $this->adminUserService->detail($user);
    }
}
