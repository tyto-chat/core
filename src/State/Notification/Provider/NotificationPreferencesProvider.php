<?php

declare(strict_types=1);

namespace App\State\Notification\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NotificationPreferences;
use App\Dto\Notification\ChannelLevelDto;
use App\Service\Notification\ChannelUserPreferenceServiceInterface;

/**
 * @implements ProviderInterface<NotificationPreferences>
 */
final readonly class NotificationPreferencesProvider implements ProviderInterface
{
    public function __construct(
        private ChannelUserPreferenceServiceInterface $preferenceService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationPreferences
    {
        $snapshot = $this->preferenceService->getAllForCurrentUser();

        $resource = new NotificationPreferences();
        foreach ($snapshot['channels'] as $channelId => $entry) {
            $resource->channels[] = new ChannelLevelDto($channelId, $entry['level'], $entry['pinState']);
        }
        $resource->mutedCommunityIds = $snapshot['mutedCommunityIds'];

        return $resource;
    }
}
