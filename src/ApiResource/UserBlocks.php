<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\User\BlockedUserDto;
use App\Dto\User\BlockStatusDto;
use App\Dto\User\BlockUserDto;
use App\State\User\Processor\BlockUserProcessor;
use App\State\User\Processor\UnblockUserProcessor;
use App\State\User\Provider\BlockedUsersProvider;
use App\State\User\Provider\BlockStatusProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'UserBlocks',
    description: 'The caller\'s personal blocklist.',
    normalizationContext: ['groups' => ['user_blocks:read']],
    denormalizationContext: ['groups' => ['user_blocks:write']],
    operations: [
        new Get(
            uriTemplate: '/me/blocks',
            security: "is_granted('ROLE_USER')",
            provider: BlockedUsersProvider::class,
            openapi: new Model\Operation(
                summary: 'List blocked users',
                description: 'Users the caller has blocked, newest first. `inEffect` is false while the blocked user is an administrator or a moderator in a community shared with the caller.',
            ),
        ),
        new Get(
            uriTemplate: '/me/blocks/{userId}',
            uriVariables: ['userId'],
            requirements: ['userId' => '\d+'],
            security: "is_granted('ROLE_USER')",
            output: BlockStatusDto::class,
            provider: BlockStatusProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the block status of a user',
                description: 'Reports whether the caller blocks the user, whether the user can be blocked (staff cannot) and whether a stored block is enforced. `404` for an unknown user.',
            ),
        ),
        new Post(
            uriTemplate: '/me/blocks',
            security: "is_granted('ROLE_USER')",
            input: BlockUserDto::class,
            output: BlockedUserDto::class,
            processor: BlockUserProcessor::class,
            status: 201,
            openapi: new Model\Operation(
                summary: 'Block a user',
                description: 'Idempotent. `422` when blocking yourself, an administrator or a moderator of a shared community, or when the 1000-block limit is reached; `404` for an unknown user.',
            ),
        ),
        new Delete(
            uriTemplate: '/me/blocks/{userId}',
            uriVariables: ['userId'],
            requirements: ['userId' => '\d+'],
            security: "is_granted('ROLE_USER')",
            read: false,
            output: false,
            processor: UnblockUserProcessor::class,
            openapi: new Model\Operation(
                summary: 'Unblock a user',
                description: 'Idempotent; returns no content.',
            ),
        ),
    ],
)]
class UserBlocks
{
    /** @var BlockedUserDto[] */
    #[Groups(['user_blocks:read'])]
    public array $items = [];
}
