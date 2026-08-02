<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Admin\AdminUserApiKeysDto;
use App\Dto\Admin\AdminUserDetailDto;
use App\Dto\Admin\AdminUserPageDto;
use App\Dto\Admin\AdminUserPatchDto;
use App\Dto\Admin\CreateAdminUserDto;
use App\Dto\ApiKey\IssueApiKeyDto;
use App\Dto\ApiKey\IssuedApiKeyDto;
use App\Entity\ApiKey;
use App\State\Admin\Processor\CreateAdminUserProcessor;
use App\State\Admin\Processor\DeleteAdminUserProcessor;
use App\State\Admin\Processor\DisableAdminUserTwoFactorProcessor;
use App\State\Admin\Processor\IssueAdminUserApiKeyProcessor;
use App\State\Admin\Processor\PatchAdminUserProcessor;
use App\State\Admin\Processor\RevokeAdminUserApiKeyProcessor;
use App\State\Admin\Provider\AdminUserApiKeysProvider;
use App\State\Admin\Provider\AdminUserProvider;
use App\State\Admin\Provider\AdminUsersProvider;

#[ApiResource(
    shortName: 'AdminUser',
    description: 'Admin-panel view of a user account: listing, profile and role management, provisioning, force-deletion, and Personal Access Token administration.',
    normalizationContext: ['groups' => ['admin_user:read']],
    denormalizationContext: ['groups' => ['admin_user:write']],
    operations: [
        new Get(
            uriTemplate: '/admin/users',
            uriVariables: [],
            security: "is_granted('ADMIN_USER_MANAGE')",
            output: AdminUserPageDto::class,
            provider: AdminUsersProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List server users',
                description: 'Paginated user list with combinable filters; also accepts `sort`/`dir` (default `id` '
                    .'descending). Boolean filters take `true`/`false` (also `1`/`0`, `yes`/`no`) — any other value '
                    .'returns `400`. Requires `ROLE_ADMIN`.',
                parameters: [
                    new Model\Parameter('search', 'query', 'Filter by name/email substring.', false, false, false, ['type' => 'string']),
                    new Model\Parameter('isAdmin', 'query', 'Filter by global-admin flag (true/false).', false, false, false, ['type' => 'boolean']),
                    new Model\Parameter('isPendingDeletion', 'query', 'Filter by pending-deletion flag (true/false).', false, false, false, ['type' => 'boolean']),
                    new Model\Parameter('isBot', 'query', 'Filter by bot flag (true/false).', false, false, false, ['type' => 'boolean']),
                    new Model\Parameter('page', 'query', 'Page number (1-based).', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('perPage', 'query', 'Rows per page (1-100, default 25).', false, false, false, ['type' => 'integer']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/admin/users',
            uriVariables: [],
            security: "is_granted('ADMIN_USER_MANAGE')",
            input: CreateAdminUserDto::class,
            output: AdminUserDetailDto::class,
            read: false,
            processor: CreateAdminUserProcessor::class,
            status: 201,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Create a user or bot account',
                description: 'Provisions an account. Human users need a unique email (`422` when missing, `409` when taken), '
                    .'are silently added as members of the given `communityIds` (no welcome message), and receive an email '
                    .'invitation to set their password. Bots have no email, ignore `communityIds`, and get no invite — '
                    .'they authenticate via Personal Access Tokens only. Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Get(
            uriTemplate: '/admin/users/{id}',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_USER_MANAGE')",
            output: AdminUserDetailDto::class,
            provider: AdminUserProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Get a user\'s admin detail',
                description: 'Full admin view of one account, including its API-key and push-subscription counts. '
                    .'Requires `ROLE_ADMIN`. Unknown id returns `404`.',
            ),
        ),
        new Patch(
            uriTemplate: '/admin/users/{id}',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_USER_MANAGE')",
            input: AdminUserPatchDto::class,
            output: AdminUserDetailDto::class,
            read: false,
            processor: PatchAdminUserProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Edit a user\'s profile or roles',
                description: 'Sparse patch: `displayName` updates the profile name, `isAdmin` grants or revokes the global '
                    .'`ROLE_ADMIN` role. No-op values are skipped; each effective change is audit-logged separately. '
                    .'Requires `ROLE_ADMIN`.',
            ),
        ),
        new Delete(
            uriTemplate: '/admin/users/{id}',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_USER_MANAGE')",
            read: false,
            processor: DeleteAdminUserProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Force-delete a user account',
                description: 'Irreversibly purges the account, bypassing the GDPR grace period. The request body must echo '
                    .'the target\'s email as `{"confirm": "<email>"}` — a mismatch returns `400`. Admins cannot delete '
                    .'themselves this way (`400`; use `/api/me/account-deletion` instead). Audit-logged. '
                    .'Requires `ROLE_ADMIN`.',
            ),
        ),
        new Get(
            uriTemplate: '/admin/users/{id}/api-keys',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_USER_MANAGE')",
            output: AdminUserApiKeysDto::class,
            provider: AdminUserApiKeysProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List a user\'s Personal Access Tokens',
                description: 'All Personal Access Tokens issued for the user, as metadata only (name, scopes, expiry, '
                    .'revocation state) — token values are never shown after issuance. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/users/{id}/api-keys',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_USER_MANAGE')",
            input: IssueApiKeyDto::class,
            output: IssuedApiKeyDto::class,
            read: false,
            status: 201,
            denormalizationContext: ['groups' => ['api_key:write']],
            normalizationContext: ['groups' => ['api_key:read']],
            processor: IssueAdminUserApiKeyProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Issue a Personal Access Token for a bot',
                description: 'Issues a scoped `pat_*` token on behalf of the target account. The target must be a bot '
                    .'(`422` for human users); the plaintext token is returned once in this response and only its hash is '
                    .'stored. Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/users/{id}/2fa/disable',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_USER_MANAGE')",
            input: false,
            output: AdminUserDetailDto::class,
            read: false,
            processor: DisableAdminUserTwoFactorProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Disable a user\'s two-factor authentication',
                description: 'Admin lockout escape hatch: wipes the TOTP secret and all recovery codes. '
                    .'Returns `404` when the user does not exist. '
                    .'Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Delete(
            uriTemplate: '/admin/users/{id}/api-keys/{keyId}',
            requirements: ['id' => '\d+', 'keyId' => '\d+'],
            // read: false makes these Links inert lookups, but they still populate $uriVariables for the processor.
            uriVariables: [
                'id' => new Link(fromClass: \App\Entity\User::class, identifiers: ['id']),
                'keyId' => new Link(fromClass: ApiKey::class, identifiers: ['id']),
            ],
            security: "is_granted('ADMIN_USER_MANAGE')",
            read: false,
            processor: RevokeAdminUserApiKeyProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Revoke a user\'s Personal Access Token',
                description: 'Marks the token as revoked (idempotent; the row is kept for audit). Returns `404` when the '
                    .'key does not exist or does not belong to the given user. Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
    ],
)]
class User
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;
}
