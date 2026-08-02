<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Admin\AdminCommunityListDto;
use App\Dto\Admin\AdminCommunityTransferDto;
use App\Dto\Admin\AdminCommunityTransferResultDto;
use App\State\Admin\Processor\DeleteAdminCommunityProcessor;
use App\State\Admin\Processor\TransferAdminCommunityProcessor;
use App\State\Admin\Provider\AdminCommunitiesProvider;

#[ApiResource(
    shortName: 'AdminCommunity',
    description: 'Admin-panel view of a community: per-community stats plus force-delete and admin-role transfer.',
    normalizationContext: ['groups' => ['admin_community:read']],
    denormalizationContext: ['groups' => ['admin_community:write']],
    operations: [
        new Get(
            uriTemplate: '/admin/communities',
            uriVariables: [],
            security: "is_granted('ADMIN_COMMUNITY_MANAGE')",
            output: AdminCommunityListDto::class,
            provider: AdminCommunitiesProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List communities with admin stats',
                description: 'Paginated community list with member and message counts per row. Supports `page`/`perPage` '
                    .'and `sort`/`dir` (default `createdAt` descending) alongside the `search` filter. '
                    .'Requires `ROLE_ADMIN`.',
                parameters: [
                    new Model\Parameter('search', 'query', 'Filter by name/identifier substring.', false, false, false, ['type' => 'string']),
                ],
            ),
        ),
        new Delete(
            uriTemplate: '/admin/communities/{identifier}',
            security: "is_granted('ADMIN_COMMUNITY_MANAGE')",
            read: false,
            processor: DeleteAdminCommunityProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Force-delete a community',
                description: 'Permanently removes the community and its content, and purges the cached community detail. '
                    .'The deletion is written to the admin audit log with the community identifier and name. '
                    .'Requires `ROLE_ADMIN`. Unknown identifier returns `404`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/communities/{identifier}/transfer',
            status: 200,
            security: "is_granted('ADMIN_COMMUNITY_MANAGE')",
            input: AdminCommunityTransferDto::class,
            output: AdminCommunityTransferResultDto::class,
            read: false,
            processor: TransferAdminCommunityProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Transfer the community admin role',
                description: 'Promotes the user given by `newAdminUserId` to community admin; the target must already be a '
                    .'member (`409` otherwise — promotion never implicitly joins anyone). With `demoteOthers: true` every '
                    .'other community admin is demoted to member; the response reports how many were demoted. '
                    .'Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
    ],
)]
class Community
{
    #[ApiProperty(identifier: true)]
    public string $identifier = '';
}
