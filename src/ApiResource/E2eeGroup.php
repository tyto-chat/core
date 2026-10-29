<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\E2ee\CommitInputDto;
use App\Dto\E2ee\CommitResultDto;
use App\Dto\E2ee\GroupStateDto;
use App\Dto\E2ee\ItemsDto;
use App\Dto\E2ee\LeaveRequestInputDto;
use App\State\E2ee\Processor\AcknowledgeWelcomeProcessor;
use App\State\E2ee\Processor\LeaveRequestProcessor;
use App\State\E2ee\Processor\SubmitCommitProcessor;
use App\State\E2ee\Provider\GroupCommitsProvider;
use App\State\E2ee\Provider\GroupStateProvider;
use App\State\E2ee\Provider\LeaveRequestsProvider;
use App\State\E2ee\Provider\WelcomesProvider;

#[ApiResource(
    shortName: 'E2eeGroup',
    description: 'MLS delivery service for encrypted conversations: serialised commits, welcomes and leave requests.',
    normalizationContext: ['groups' => ['e2ee:read']],
    denormalizationContext: ['groups' => ['e2ee:write']],
    operations: [
        new Post(
            uriTemplate: '/conversations/{conversation}/mls/commits',
            uriVariables: ['conversation'],
            security: "is_granted('ROLE_USER')",
            input: CommitInputDto::class,
            output: CommitResultDto::class,
            read: false,
            processor: SubmitCommitProcessor::class,
            status: 201,
            openapi: new Model\Operation(
                summary: 'Submit an MLS commit to an encrypted conversation',
                description: 'Participants only. `epoch` must be the current epoch + 1, else `409` with `{code: "stale_epoch", currentEpoch}`. '
                    .'`commitId` is the idempotency key: a resend returns the stored result with `200`. `generation` is the current group '
                    .'generation, or current + 1 from a device of an identity reset since that generation began; older generations are then '
                    .'frozen. `welcomes` target active devices of participants. `leaveRequestId` marks a pending leave request fulfilled; '
                    .'a second commit for it gets `409 stale_epoch`. `deviceId` is the caller\'s committing device.',
            ),
        ),
        new Get(
            uriTemplate: '/conversations/{conversation}/mls/state',
            uriVariables: ['conversation'],
            security: "is_granted('ROLE_USER')",
            output: GroupStateDto::class,
            provider: GroupStateProvider::class,
            openapi: new Model\Operation(
                summary: 'Current MLS epoch, generation and group info of an encrypted conversation',
                description: 'Epoch and generation are 0 and `groupInfo` is null before the first commit.',
            ),
        ),
        new Get(
            uriTemplate: '/conversations/{conversation}/mls/commits',
            uriVariables: ['conversation'],
            security: "is_granted('ROLE_USER')",
            output: ItemsDto::class,
            provider: GroupCommitsProvider::class,
            openapi: new Model\Operation(
                summary: 'Commits after an epoch',
                description: '`?after=<epoch>`; oldest first, at most 100 per call.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/welcomes',
            security: "is_granted('ROLE_USER')",
            output: ItemsDto::class,
            provider: WelcomesProvider::class,
            openapi: new Model\Operation(
                summary: 'Undelivered welcomes for the caller\'s active devices',
                description: 'Oldest first, at most 50 per call; acknowledge them, or pass `?after=<welcomeId>` to page past ones kept for later. Optional `?deviceId=` narrows the list to one device.',
            ),
        ),
        new Post(
            uriTemplate: '/e2ee/welcomes/{welcomeId}/ack',
            uriVariables: ['welcomeId'],
            security: "is_granted('ROLE_USER')",
            input: false,
            output: false,
            read: false,
            processor: AcknowledgeWelcomeProcessor::class,
            status: 204,
            openapi: new Model\Operation(summary: 'Mark a welcome delivered', description: 'Idempotent. `404` for a welcome of another user.'),
        ),
        new Post(
            uriTemplate: '/e2ee/devices/{deviceId}/leave',
            uriVariables: ['deviceId'],
            security: "is_granted('ROLE_USER')",
            input: LeaveRequestInputDto::class,
            output: false,
            read: false,
            processor: LeaveRequestProcessor::class,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Remove this device with a signed leave request',
                description: '`signature` is the device key\'s Ed25519 signature over the deterministic CBOR map '
                    .'`{deviceId, generation, requestedAt, leaveRequestId}`. Revokes the device and leaves the request pending in every '
                    .'encrypted conversation of the caller until a commit carrying its `leaveRequestId` removes the device. Idempotent.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/leave-requests',
            security: "is_granted('ROLE_USER')",
            output: ItemsDto::class,
            provider: LeaveRequestsProvider::class,
            openapi: new Model\Operation(
                summary: 'Pending leave requests of an encrypted conversation',
                description: '`?conversation=<identifier>`; participants only.',
            ),
        ),
    ],
)]
final class E2eeGroup
{
}
