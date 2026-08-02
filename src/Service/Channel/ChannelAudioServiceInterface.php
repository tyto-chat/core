<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Entity\Channel;
use App\Entity\ChannelParticipant;
use App\Entity\Community;
use App\Entity\User;

interface ChannelAudioServiceInterface
{
    /** @return ChannelParticipant[] */
    public function getParticipants(Channel $channel): array;

    public function joinAudioChannelAsCurrentUser(Channel $channel): void;

    public function joinAudioChannel(User $user, Channel $channel): void;

    public function leaveAudioChannel(User $user, Channel $channel): void;

    public function publishAudioChannelParticipants(Channel $channel): void;

    public function reconcileAudioParticipants(Channel $channel): int;

    /** Room closed on LiveKit — drop every participant row for the channel. */
    public function handleRoomFinished(Channel $channel): void;

    /**
     * @param int[] $userIds
     *
     * @return int[] subset of $userIds that have at least one active voice participation row
     */
    public function filterActiveVoiceUserIds(array $userIds): array;

    public function disconnectFromChannel(User $user, Channel $channel): void;

    public function disconnectFromCommunity(User $user, Community $community): void;

    public function disconnectFromAllRooms(User $user): void;

    public function reconcileActiveRooms(): void;
}
