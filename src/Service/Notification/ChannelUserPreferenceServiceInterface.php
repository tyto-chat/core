<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Channel\ChannelPinState;

interface ChannelUserPreferenceServiceInterface
{
    public function getChannelLevel(User $user, Channel $channel): ChannelNotificationLevel;

    public function isCommunityMuted(User $user, Community $community): bool;

    public function setChannelLevel(Channel $channel, ?ChannelNotificationLevel $level): void;

    public function setPinState(Channel $channel, ?ChannelPinState $state): void;

    public function setCommunityMuted(Community $community, bool $muted): void;

    /**
     * @return array{channels: array<int, array{level: ChannelNotificationLevel, pinState: ?ChannelPinState}>, mutedCommunityIds: list<int>}
     */
    public function getAllForCurrentUser(): array;

    /**
     * @param int[] $candidateUserIds
     *
     * @return int[]
     */
    public function filterRecipients(Channel $channel, array $candidateUserIds, string $category): array;
}
