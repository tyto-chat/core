<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\User\InvitableUserDto;
use App\State\User\Provider\InvitableUsersProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'InvitableUsers',
    description: 'Users the caller may start a direct message with.',
    normalizationContext: ['groups' => ['invitable_users:read']],
    operations: [
        new Get(
            uriTemplate: '/me/invitable-users',
            security: "is_granted('ROLE_USER')",
            provider: InvitableUsersProvider::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'List users invitable to a direct message',
                description: 'Autocomplete source for starting a DM: returns non-bot users who share at least one community '
                    .'with the authenticated caller (global admins may look up any user). Optional `search` filters by '
                    .'display name or email; `limit` is clamped to 1-50.',
                parameters: [
                    new Model\Parameter('search', 'query', 'Partial display-name / email filter.', false, false, false, ['type' => 'string']),
                    new Model\Parameter('limit', 'query', 'Max results (1-50, default 20).', false, false, false, ['type' => 'integer']),
                ],
            ),
        ),
    ],
)]
class InvitableUsers
{
    /** @var InvitableUserDto[] */
    #[Groups(['invitable_users:read'])]
    public array $items = [];
}
