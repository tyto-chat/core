<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Enum\Admin\AdminAuditAction;

interface AdminAuditLoggerInterface
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function record(
        AdminAuditAction $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $payload = null,
    ): void;
}
