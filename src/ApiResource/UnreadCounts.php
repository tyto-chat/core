<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Notification\Provider\UnreadCountsProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    description: 'Per-caller unread notification counts, keyed by community id.',
    normalizationContext: ['groups' => ['unread_counts:read']],
    formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
    operations: [
        new Get(
            uriTemplate: '/notifications/unread-counts',
            security: "is_granted('ROLE_USER')",
            provider: UnreadCountsProvider::class,
            extraProperties: ['scopeResource' => 'notifications'],
            openapi: new Model\Operation(
                summary: 'Get unread notification counts',
                description: 'Returns the authenticated caller\'s unread notification counts keyed by community id, with '
                    .'the literal `dm` key carrying the direct-message total. Drives the client\'s sidebar badges.',
            ),
        ),
    ],
)]
class UnreadCounts
{
    /** @var array<string, int> */
    #[Groups(['unread_counts:read'])]
    public array $counts = [];
}
