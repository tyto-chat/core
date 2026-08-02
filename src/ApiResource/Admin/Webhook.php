<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Admin\AdminWebhookDeliveryPageDto;
use App\Dto\Admin\AdminWebhookDto;
use App\Dto\Admin\AdminWebhookListDto;
use App\Dto\Admin\AdminWebhookReplayResultDto;
use App\Dto\Admin\AdminWebhookSecretDto;
use App\Dto\Admin\AdminWebhookTestResultDto;
use App\Dto\Admin\AdminWebhookTriggersDto;
use App\Dto\Admin\AdminWebhookWithSecretDto;
use App\Dto\Admin\CreateWebhookDto;
use App\State\Admin\Processor\CreateWebhookProcessor;
use App\State\Admin\Processor\DeleteWebhookProcessor;
use App\State\Admin\Processor\RegenerateWebhookSecretProcessor;
use App\State\Admin\Processor\ReplayWebhookProcessor;
use App\State\Admin\Processor\TestWebhookProcessor;
use App\State\Admin\Processor\UpdateWebhookProcessor;
use App\State\Admin\Provider\AdminWebhookDeliveriesProvider;
use App\State\Admin\Provider\AdminWebhookProvider;
use App\State\Admin\Provider\AdminWebhooksProvider;
use App\State\Admin\Provider\AdminWebhookTriggersProvider;

#[ApiResource(
    shortName: 'AdminWebhook',
    description: 'An admin-defined outbound webhook: a trigger key plus target URL, with HMAC-signed async deliveries and a per-webhook delivery log.',
    normalizationContext: ['groups' => ['admin_webhook:read']],
    denormalizationContext: ['groups' => ['admin_webhook:write']],
    operations: [
        new Get(
            uriTemplate: '/admin/webhooks/triggers',
            uriVariables: [],
            security: "is_granted('ADMIN_WEBHOOKS')",
            output: AdminWebhookTriggersDto::class,
            provider: AdminWebhookTriggersProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List available webhook triggers',
                description: 'Returns the registered trigger catalog: key, label, description, and the filter fields each '
                    .'trigger supports (with the required ones flagged). Requires `ROLE_ADMIN`.',
            ),
        ),
        new Get(
            uriTemplate: '/admin/webhooks',
            uriVariables: [],
            security: "is_granted('ADMIN_WEBHOOKS')",
            output: AdminWebhookListDto::class,
            provider: AdminWebhooksProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List outbound webhooks',
                description: 'All webhooks, newest first, each with its replayable (queued or failed) delivery count. '
                    .'Secrets are never included. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/webhooks',
            security: "is_granted('ADMIN_WEBHOOKS')",
            input: CreateWebhookDto::class,
            output: AdminWebhookWithSecretDto::class,
            processor: CreateWebhookProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Create an outbound webhook',
                description: 'Creates an active webhook and generates its HMAC signing secret, which is returned in this '
                    .'response only — it cannot be retrieved later, only regenerated. An unregistered `triggerKey` or a '
                    .'missing required filter field returns `422`. Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Get(
            uriTemplate: '/admin/webhooks/{id}',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_WEBHOOKS')",
            output: AdminWebhookDto::class,
            provider: AdminWebhookProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Get a webhook',
                description: 'Single webhook detail with its replayable delivery count; the secret is not included. '
                    .'Requires `ROLE_ADMIN`. Unknown id returns `404`.',
            ),
        ),
        new Patch(
            uriTemplate: '/admin/webhooks/{id}',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_WEBHOOKS')",
            input: false,
            output: AdminWebhookDto::class,
            read: false,
            processor: UpdateWebhookProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Update a webhook',
                description: 'Sparse patch of `name`, `url`, `filters`, and `isActive`; malformed fields return `400`, '
                    .'filters failing the trigger\'s required-field check return `422`. Re-activating a disabled webhook '
                    .'clears its auto-disable reason, and passing `replayPending: true` alongside that re-activation '
                    .'re-queues the deliveries buffered while it was off. Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Delete(
            uriTemplate: '/admin/webhooks/{id}',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_WEBHOOKS')",
            read: false,
            processor: DeleteWebhookProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Delete a webhook',
                description: 'Permanently removes the webhook and its delivery history. Audit-logged with the webhook id '
                    .'and name. Requires `ROLE_ADMIN`. Unknown id returns `404`.',
            ),
        ),
        new Get(
            uriTemplate: '/admin/webhooks/{id}/deliveries',
            requirements: ['id' => '\d+'],
            security: "is_granted('ADMIN_WEBHOOKS')",
            output: AdminWebhookDeliveryPageDto::class,
            provider: AdminWebhookDeliveriesProvider::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'List a webhook\'s delivery log',
                description: 'Paginated delivery attempts for one webhook, newest first, with per-row status, attempts, and '
                    .'error details plus the current replayable count. Requires `ROLE_ADMIN`.',
                parameters: [
                    new Model\Parameter('page', 'query', 'Page number (1-based).', false, false, false, ['type' => 'integer']),
                    new Model\Parameter('perPage', 'query', 'Rows per page (1-100, default 25).', false, false, false, ['type' => 'integer']),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/admin/webhooks/{id}/regenerate-secret',
            requirements: ['id' => '\d+'],
            status: 200,
            security: "is_granted('ADMIN_WEBHOOKS')",
            input: false,
            output: AdminWebhookSecretDto::class,
            read: false,
            processor: RegenerateWebhookSecretProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Regenerate a webhook\'s signing secret',
                description: 'Replaces the HMAC signing secret, immediately invalidating the old one — receivers must switch '
                    .'to the new secret. The new value is returned in this response only. Audit-logged. '
                    .'Requires `ROLE_ADMIN`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/webhooks/{id}/replay',
            requirements: ['id' => '\d+'],
            status: 200,
            security: "is_granted('ADMIN_WEBHOOKS')",
            input: false,
            output: AdminWebhookReplayResultDto::class,
            read: false,
            processor: ReplayWebhookProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Replay pending webhook deliveries',
                description: 'Resets every queued or failed delivery of this webhook to `pending` with its attempt counter '
                    .'zeroed and re-dispatches each for async delivery, oldest first. Returns the number of deliveries '
                    .'re-queued. Audit-logged. Requires `ROLE_ADMIN`.',
            ),
        ),
        new Post(
            uriTemplate: '/admin/webhooks/{id}/test',
            requirements: ['id' => '\d+'],
            status: 200,
            security: "is_granted('ADMIN_WEBHOOKS')",
            input: false,
            output: AdminWebhookTestResultDto::class,
            read: false,
            processor: TestWebhookProcessor::class,
            extraProperties: ['scope' => 'admin'],
            openapi: new Model\Operation(
                summary: 'Send a test webhook delivery',
                description: 'Builds a synthetic payload for the webhook\'s trigger from placeholder values, records it as a '
                    .'real delivery, and dispatches it asynchronously; returns the delivery id and its (initially `pending`) '
                    .'status. Returns `422` if the trigger key is no longer registered. Audit-logged. '
                    .'Requires `ROLE_ADMIN`.',
            ),
        ),
    ],
)]
class Webhook
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;
}
