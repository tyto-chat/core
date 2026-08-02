<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\Message\Provider\ReactionUsersProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/messages/{id}/reactions/{emoji}',
            normalizationContext: ['groups' => ['reaction_users:read']],
            paginationEnabled: false,
            provider: ReactionUsersProvider::class,
            extraProperties: ['scopeResource' => 'messages'],
        ),
    ],
    outputFormats: ['jsonld' => ['application/ld+json']],
)]
final readonly class ReactionUserDto
{
    public function __construct(
        #[Groups(['reaction_users:read'])]
        public string $profile = '',
        #[Groups(['reaction_users:read'])]
        public string $name = '',
    ) {
    }
}
