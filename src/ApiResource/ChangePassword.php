<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\User\ChangePasswordDto;
use App\State\User\Processor\ChangePasswordProcessor;

#[ApiResource(
    description: 'Self-service password change for the authenticated user.',
    operations: [
        new Post(
            uriTemplate: '/users/me/change-password',
            security: "is_granted('ROLE_USER')",
            input: ChangePasswordDto::class,
            output: false,
            processor: ChangePasswordProcessor::class,
            openapi: new Model\Operation(
                summary: 'Change my password',
                description: 'Replaces the caller\'s password. Requires the correct current password '
                    .'(`400` when it does not match) and a new password of at least 8 characters. '
                    .'Returns no content on success.',
            ),
        ),
    ]
)]
class ChangePassword
{
}
