<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\E2ee\AccountDto;
use App\Dto\E2ee\ItemsDto;
use App\Dto\E2ee\OwnDeviceDto;
use App\Dto\E2ee\RegisterAccountDto;
use App\Dto\E2ee\RegisterDeviceDto;
use App\Dto\E2ee\RevokeDeviceDto;
use App\Dto\E2ee\UploadKeyPackagesDto;
use App\Dto\E2ee\UserDevicesDto;
use App\State\E2ee\Processor\ClaimKeyPackagesProcessor;
use App\State\E2ee\Processor\RegisterAccountProcessor;
use App\State\E2ee\Processor\RegisterDeviceProcessor;
use App\State\E2ee\Processor\RevokeDeviceProcessor;
use App\State\E2ee\Processor\UploadKeyPackagesProcessor;
use App\State\E2ee\Provider\OwnDevicesProvider;
use App\State\E2ee\Provider\UserDevicesProvider;
use App\State\E2ee\Provider\UserRevocationsProvider;

#[ApiResource(
    shortName: 'E2eeRegistry',
    description: 'End-to-end encryption account keys, devices and signed revocations.',
    normalizationContext: ['groups' => ['e2ee:read']],
    denormalizationContext: ['groups' => ['e2ee:write']],
    operations: [
        new Post(
            uriTemplate: '/e2ee/account',
            security: "is_granted('ROLE_USER')",
            input: RegisterAccountDto::class,
            output: AccountDto::class,
            processor: RegisterAccountProcessor::class,
            status: 201,
            openapi: new Model\Operation(
                summary: 'Register the caller\'s account signing key',
                description: 'Once per user; sets generation 1. `publicKey` is a base64 Ed25519 public key. `409` when an account already exists.',
            ),
        ),
        new Post(
            uriTemplate: '/e2ee/devices',
            security: "is_granted('ROLE_USER')",
            input: RegisterDeviceDto::class,
            output: OwnDeviceDto::class,
            processor: RegisterDeviceProcessor::class,
            status: 201,
            openapi: new Model\Operation(
                summary: 'Register a device',
                description: '`credential` is the account key\'s Ed25519 signature over the deterministic CBOR map `{server, accountId, deviceId, generation, devicePublicKey}`. `server` is this server\'s origin. `422` when the account is missing or the credential does not verify; `409` for a reused device id.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/devices',
            security: "is_granted('ROLE_USER')",
            output: ItemsDto::class,
            provider: OwnDevicesProvider::class,
            openapi: new Model\Operation(summary: 'List the caller\'s active devices'),
        ),
        new Delete(
            uriTemplate: '/e2ee/devices/{deviceId}',
            uriVariables: ['deviceId'],
            security: "is_granted('ROLE_USER')",
            input: RevokeDeviceDto::class,
            output: false,
            read: false,
            deserialize: true,
            validate: true,
            processor: RevokeDeviceProcessor::class,
            openapi: new Model\Operation(
                summary: 'Revoke one of the caller\'s devices',
                description: 'Body `{revocation: {signerDeviceId, revokedAt, signature}}`: an Ed25519 signature by an active device of the same account (a sibling, or the device itself as a leave request) over the deterministic CBOR map `{deviceId, generation, revokedAt, signerDeviceId}`. Idempotent. `404` for a device the caller does not own.',
            ),
        ),
        new Post(
            uriTemplate: '/e2ee/devices/{deviceId}/key-packages',
            uriVariables: ['deviceId'],
            security: "is_granted('ROLE_USER')",
            input: UploadKeyPackagesDto::class,
            output: false,
            read: false,
            processor: UploadKeyPackagesProcessor::class,
            status: 204,
            openapi: new Model\Operation(
                summary: 'Publish key packages for one of the caller\'s devices',
                description: '`packages` are one-time MLS key packages, `lastResort` (optional) replaces the device\'s last-resort package. Each is `{data: base64, expiresAt: unix seconds}`; the expiry must be in the future and at most 366 days ahead. At most 100 unexpired one-time packages are stored per device. `422` for a revoked device, `404` for a device the caller does not own.',
            ),
        ),
        new Post(
            uriTemplate: '/e2ee/users/{userId}/key-package',
            uriVariables: ['userId'],
            requirements: ['userId' => '\d+'],
            security: "is_granted('ROLE_USER')",
            input: false,
            output: ItemsDto::class,
            read: false,
            processor: ClaimKeyPackagesProcessor::class,
            status: 200,
            openapi: new Model\Operation(
                summary: 'Claim one key package per reachable device of a user',
                description: 'Consumes one one-time package per active device, falling back to the device\'s unexpired last-resort package. Devices with neither are skipped. `409` when no device of the user is reachable.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/users/{userId}/devices',
            uriVariables: ['userId'],
            requirements: ['userId' => '\d+'],
            security: "is_granted('ROLE_USER')",
            output: UserDevicesDto::class,
            provider: UserDevicesProvider::class,
            openapi: new Model\Operation(
                summary: 'List a user\'s active devices',
                description: 'Account public key, generation and active devices of a user who shares a community with the caller. `accountPublicKey` is null when the user has not set up encryption.',
            ),
        ),
        new Get(
            uriTemplate: '/e2ee/users/{userId}/revocations',
            uriVariables: ['userId'],
            requirements: ['userId' => '\d+'],
            security: "is_granted('ROLE_USER')",
            output: ItemsDto::class,
            provider: UserRevocationsProvider::class,
            openapi: new Model\Operation(
                summary: 'List a user\'s signed device revocations',
                description: 'Every revocation of the user\'s devices, across generations, so peers can keep a local revocation set.',
            ),
        ),
    ],
)]
final class E2eeRegistry
{
}
