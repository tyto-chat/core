<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\User\PasswordResetDto;
use App\State\User\Processor\SetPasswordProcessor;

#[ApiResource(
    description: 'Completes the email-token password-reset flow.',
    operations: [
        new Post(
            uriTemplate: '/password',
            input: PasswordResetDto::class,
            output: false,
            processor: SetPasswordProcessor::class,
            openapi: new Model\Operation(
                summary: 'Reset a password with an emailed token',
                description: 'Anonymous endpoint. Sets a new password when `email` and `token` match an unexpired reset '
                    .'request; the token is marked used on success. Unknown emails and invalid or expired tokens are '
                    .'silently ignored, so the response never reveals whether an account exists.',
            ),
        ),
    ]
)]
class PasswordReset
{
}
