<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\User\ConfirmTwoFactorDto;
use App\Dto\User\CurrentPasswordDto;
use App\Dto\User\TwoFactorRecoveryCodesDto;
use App\Dto\User\TwoFactorSetupDto;
use App\Dto\User\TwoFactorStatusDto;
use App\State\User\Processor\ConfirmTwoFactorProcessor;
use App\State\User\Processor\DisableTwoFactorProcessor;
use App\State\User\Processor\RegenerateRecoveryCodesProcessor;
use App\State\User\Processor\SetupTwoFactorProcessor;
use App\State\User\Provider\TwoFactorStatusProvider;

#[ApiResource(
    description: 'Self-service two-factor authentication (TOTP) management for the authenticated user.',
    operations: [
        new Get(
            uriTemplate: '/users/me/2fa',
            security: "is_granted('ROLE_USER')",
            output: TwoFactorStatusDto::class,
            provider: TwoFactorStatusProvider::class,
            openapi: new Model\Operation(
                summary: 'Get my two-factor status',
                description: 'Reports whether TOTP two-factor authentication is enabled, since when, and how many recovery codes remain unused.',
            ),
        ),
        new Post(
            uriTemplate: '/users/me/2fa/setup',
            security: "is_granted('ROLE_USER')",
            input: false,
            output: TwoFactorSetupDto::class,
            processor: SetupTwoFactorProcessor::class,
            openapi: new Model\Operation(
                summary: 'Begin two-factor enrollment',
                description: 'Generates a TOTP secret and returns it with its otpauth:// provisioning URI. '
                    .'Not yet active — call the confirm endpoint with a valid code to enable. '
                    .'`422` when two-factor authentication is already enabled.',
            ),
        ),
        new Post(
            uriTemplate: '/users/me/2fa/confirm',
            security: "is_granted('ROLE_USER')",
            input: ConfirmTwoFactorDto::class,
            output: TwoFactorRecoveryCodesDto::class,
            processor: ConfirmTwoFactorProcessor::class,
            openapi: new Model\Operation(
                summary: 'Confirm two-factor enrollment',
                description: 'Verifies a TOTP code against the pending secret and enables two-factor authentication. '
                    .'Returns ten single-use recovery codes — shown exactly once. '
                    .'`401` on a wrong code, `422` when setup was not started or already enabled.',
            ),
        ),
        new Post(
            uriTemplate: '/users/me/2fa/disable',
            security: "is_granted('ROLE_USER')",
            input: CurrentPasswordDto::class,
            output: false,
            processor: DisableTwoFactorProcessor::class,
            openapi: new Model\Operation(
                summary: 'Disable two-factor authentication',
                description: 'Turns off TOTP and deletes all recovery codes. Requires the correct current password '
                    .'(`400` on mismatch, `422` when not enabled). Returns no content on success.',
            ),
        ),
        new Post(
            uriTemplate: '/users/me/2fa/recovery-codes',
            security: "is_granted('ROLE_USER')",
            input: CurrentPasswordDto::class,
            output: TwoFactorRecoveryCodesDto::class,
            processor: RegenerateRecoveryCodesProcessor::class,
            openapi: new Model\Operation(
                summary: 'Regenerate recovery codes',
                description: 'Replaces all recovery codes with a fresh set of ten — previous codes stop working. '
                    .'Requires the correct current password (`400` on mismatch, `422` when not enabled).',
            ),
        ),
    ]
)]
class TwoFactor
{
}
