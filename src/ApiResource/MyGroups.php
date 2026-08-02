<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\UserGroup\MyGroupDto;
use App\State\UserGroup\Provider\MyGroupsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    shortName: 'MyGroups',
    description: 'The user groups the caller owns or belongs to, across all communities.',
    normalizationContext: ['groups' => ['my_groups:read']],
    operations: [
        new Get(
            uriTemplate: '/me/groups',
            security: "is_granted('ROLE_USER')",
            provider: MyGroupsProvider::class,
            extraProperties: ['scopeResource' => 'profile'],
            openapi: new Model\Operation(
                summary: 'List my groups',
                description: 'Returns every user group the caller owns or is a member of, across all their '
                    .'communities, with each group\'s community, member count, and an ownership flag.',
            ),
        ),
    ],
)]
class MyGroups
{
    /** @var MyGroupDto[] */
    #[Groups(['my_groups:read'])]
    public array $items = [];
}
