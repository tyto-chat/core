<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\AdminAuditActionsDto;
use App\Enum\Admin\AdminAuditAction;

/**
 * @implements ProviderInterface<AdminAuditActionsDto>
 */
final readonly class AdminAuditActionsProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminAuditActionsDto
    {
        $dto = new AdminAuditActionsDto();
        $dto->actions = array_map(static fn (AdminAuditAction $a): string => $a->value, AdminAuditAction::cases());

        return $dto;
    }
}
