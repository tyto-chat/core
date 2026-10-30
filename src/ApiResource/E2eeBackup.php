<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\Dto\E2ee\GenerationDto;
use App\Dto\E2ee\HistoryHeadsDto;
use App\Dto\E2ee\HistoryPageDto;
use App\Dto\E2ee\HistoryRecordInputDto;
use App\Dto\E2ee\IdentityBackupDto;
use App\Dto\E2ee\IdentityBackupInputDto;
use App\Dto\E2ee\ResetAccountDto;
use App\State\E2ee\Processor\AppendHistoryRecordProcessor;
use App\State\E2ee\Processor\ResetAccountProcessor;
use App\State\E2ee\Processor\StoreIdentityBackupProcessor;
use App\State\E2ee\Provider\HistoryHeadsProvider;
use App\State\E2ee\Provider\HistoryPageProvider;
use App\State\E2ee\Provider\IdentityBackupProvider;

#[ApiResource(
    shortName: 'E2eeBackup',
    description: 'Opaque, client-encrypted recovery backups of an end-to-end encryption account, and the account reset.',
    normalizationContext: ['groups' => ['e2ee:read']],
    denormalizationContext: ['groups' => ['e2ee:write']],
    operations: [
        new Put(
            uriTemplate: '/e2ee/identity-backup',
            security: "is_granted('ROLE_USER')",
            input: IdentityBackupInputDto::class,
            output: false,
            read: false,
            processor: StoreIdentityBackupProcessor::class,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Store the caller\'s identity backup',
                description: 'Write-once per account generation: `409` when this generation already has one (only a reset opens a new generation). `blob` is base64, at most 48 KiB decoded, and opaque to the server.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/identity-backup',
            security: "is_granted('ROLE_USER')",
            output: IdentityBackupDto::class,
            provider: IdentityBackupProvider::class,
            openapi: new Model\Operation(
                summary: 'Fetch the caller\'s identity backup',
                description: 'The backup of the current generation. `404` when there is none.',
            ),
        ),
        new Post(
            uriTemplate: '/e2ee/history-backup/{deviceId}/records',
            uriVariables: ['deviceId'],
            security: "is_granted('ROLE_USER')",
            input: HistoryRecordInputDto::class,
            output: false,
            read: false,
            processor: AppendHistoryRecordProcessor::class,
            status: 201,
            openapi: new Model\Operation(
                summary: 'Append a history backup record for one of the caller\'s devices',
                description: 'Accepted only when `counter` is the device\'s last counter plus one; otherwise `409 {code: history_head, head}`. Resending an accepted counter with the identical `blob` returns `200`. `blob` is base64, at most 768 KiB decoded. `signature` is the device key\'s Ed25519 signature over the deterministic CBOR map `{deviceId, generation, counter, blob: SHA-256 of the decoded blob}`. `422` for a removed device or a bad signature, `404` for a device the caller does not own.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/history-backup',
            security: "is_granted('ROLE_USER')",
            output: HistoryPageDto::class,
            provider: HistoryPageProvider::class,
            openapi: new Model\Operation(
                summary: 'List the caller\'s history backup records',
                description: 'Records of every device of the account, ordered by device id then counter, at most `limit` (default and maximum 100). Pass the returned `next` as `after` for the following page; `next` is null on the last page.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/history-backup/heads',
            security: "is_granted('ROLE_USER')",
            output: HistoryHeadsDto::class,
            provider: HistoryHeadsProvider::class,
            openapi: new Model\Operation(
                summary: 'Last history record counter per device',
                description: '`heads` maps each device id with records to its last counter, so a device can tell when the server serves less than it has seen.',
            ),
        ),
        new Post(
            uriTemplate: '/e2ee/reset',
            security: "is_granted('ROLE_USER')",
            input: ResetAccountDto::class,
            output: GenerationDto::class,
            read: false,
            processor: ResetAccountProcessor::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Reset the caller\'s encryption identity',
                description: 'In one transaction: bumps the generation, removes every device, replaces the account public key and the identity backup, and deletes every history record. `generation` is the generation the caller is replacing; `409 {code: stale_generation, currentGeneration}` when the account has moved on, so a replayed reset never removes devices set up after it. Returns the new generation.',
            ),
        ),
    ],
)]
final class E2eeBackup
{
}
