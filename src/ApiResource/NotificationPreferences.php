<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\Notification\ChannelLevelDto;
use App\State\Notification\Provider\NotificationPreferencesProvider;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    description: 'The caller\'s notification-preference snapshot: per-channel levels plus muted community ids.',
    normalizationContext: ['groups' => ['notification_preferences:read']],
    formats: ['jsonld' => ['application/ld+json'], 'json' => ['application/json']],
    operations: [
        new Get(
            uriTemplate: '/me/notification-preferences',
            security: "is_granted('ROLE_USER')",
            provider: NotificationPreferencesProvider::class,
            extraProperties: ['scopeResource' => 'notifications'],
            openapi: new Model\Operation(
                summary: 'Get my notification preferences',
                description: 'Returns the caller\'s sparse per-channel notification levels (with pin state) '
                    .'and the ids of communities they muted, in one request. Read-only; changes go through '
                    .'the per-channel level and per-community mute endpoints.',
            ),
        ),
    ],
)]
class NotificationPreferences
{
    /** @var ChannelLevelDto[] */
    #[Groups(['notification_preferences:read'])]
    public array $channels = [];

    /** @var int[] */
    #[Groups(['notification_preferences:read'])]
    public array $mutedCommunityIds = [];
}
