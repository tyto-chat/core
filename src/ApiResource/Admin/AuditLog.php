<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\Admin\AdminAuditActionsDto;
use App\Dto\Admin\AdminAuditPageDto;
use App\State\Admin\Provider\AdminAuditActionsProvider;
use App\State\Admin\Provider\AdminAuditLogProvider;

#[ApiResource(
    shortName: 'AdminAuditLog',
    description: 'The append-only audit trail of administrative actions performed through the admin panel.',
    normalizationContext: ['groups' => ['admin_audit:read']],
    operations: [
        new Get(
            uriTemplate: '/admin/audit-log',
            security: "is_granted('ADMIN_PANEL_VIEW')",
            output: AdminAuditPageDto::class,
            provider: AdminAuditLogProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List admin audit-log entries',
                description: 'Paginated audit trail, newest first. All filters are optional and combine (AND); an unknown '
                    .'`action` value returns `400`. Each row carries the acting admin (when still resolvable), the target '
                    .'type/id, and a structured payload of what changed. Requires `ROLE_ADMIN`.',
                parameters: [
                    new Model\Parameter('page', 'query', 'Page number (1-based).', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('perPage', 'query', 'Rows per page (1-100, default 25).', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('action', 'query', 'Filter by audit action (see /admin/audit-log/actions).', false, false, false, ['type' => 'string']),
                    new Model\Parameter('actorId', 'query', 'Filter by acting admin id.', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('targetType', 'query', 'Filter by target entity type.', false, false, false, ['type' => 'string']),
                    new Model\Parameter('targetId', 'query', 'Filter by target entity id.', false, false, false, ['type' => 'integer']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/admin/audit-log/actions',
            security: "is_granted('ADMIN_PANEL_VIEW')",
            output: AdminAuditActionsDto::class,
            provider: AdminAuditActionsProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List known audit action types',
                description: 'Returns every audit action value the server can record, for use as the `action` filter on '
                    .'the audit-log list. Requires `ROLE_ADMIN`.',
            ),
        ),
    ],
)]
class AuditLog
{
}
