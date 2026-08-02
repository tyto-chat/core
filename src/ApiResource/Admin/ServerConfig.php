<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Admin\AdminOnboardingResultDto;
use App\Dto\Admin\ServerConfigDto;
use App\Dto\Admin\ServerConfigPatchDto;
use App\Dto\Admin\TestEmailDto;
use App\Dto\Admin\TestEmailResultDto;
use App\State\Admin\Processor\CompleteAdminOnboardingProcessor;
use App\State\Admin\Processor\SaveServerConfigProcessor;
use App\State\Admin\Processor\TestEmailProcessor;
use App\State\Admin\Provider\ServerConfigProvider;

#[ApiResource(
    shortName: 'ServerConfig',
    description: 'The singleton server configuration: every admin-tunable setting from the settings registry (branding, registration, SMTP, attachment and rate-limit knobs).',
    normalizationContext: ['groups' => ['admin_server_config:read']],
    denormalizationContext: ['groups' => ['admin_server_config:write']],
    operations: [
        new Get(
            uriTemplate: '/admin/server-config',
            security: "is_granted('ADMIN_SERVER_CONFIG')",
            output: ServerConfigDto::class,
            provider: ServerConfigProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Get the server configuration',
                description: 'Returns every non-secret setting from the settings registry, with registry defaults filled in '
                    .'for keys that were never overridden. Secret values (e.g. the SMTP password) are never returned. '
                    .'Requires `ROLE_ADMIN`.',
            ),
        ),
        new Patch(
            uriTemplate: '/admin/server-config',
            security: "is_granted('ADMIN_SERVER_CONFIG')",
            input: ServerConfigPatchDto::class,
            output: ServerConfigDto::class,
            read: false,
            processor: SaveServerConfigProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Update server configuration settings',
                description: 'Sparse patch against the settings registry: only the fields present in the body are applied, '
                    .'unchanged values are skipped, and every effective change is written to the admin audit log. '
                    .'`smtpPassword` is write-only — an empty or absent value leaves the stored (encrypted) password untouched. '
                    .'Returns the full updated configuration. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/server-config/onboarding/complete',
            security: "is_granted('ADMIN_SERVER_CONFIG')",
            input: false,
            output: AdminOnboardingResultDto::class,
            processor: CompleteAdminOnboardingProcessor::class,
            openapi: new Model\Operation(
                summary: 'Mark admin onboarding as complete',
                description: 'One-shot flag: records the `adminOnboardedAt` timestamp in the settings registry and audit-logs '
                    .'the bootstrap completion. Idempotent — calling again keeps the original timestamp. '
                    .'Requires `ROLE_ADMIN`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/server-config/test-email',
            security: "is_granted('ADMIN_SERVER_CONFIG')",
            input: TestEmailDto::class,
            output: TestEmailResultDto::class,
            processor: TestEmailProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Send a test email',
                description: 'Sends a canned test message to the given address through the currently configured mailer '
                    .'transport. Transport failures do not error the request — the response carries `ok: false` plus the '
                    .'transport error message. The attempt is audit-logged either way. Requires `ROLE_ADMIN`.',
            ),
        ),
    ],
)]
class ServerConfig
{
}
