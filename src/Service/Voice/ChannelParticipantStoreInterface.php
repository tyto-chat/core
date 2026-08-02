<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Channel;
use App\Entity\ChannelParticipant;
use App\Entity\User;

interface ChannelParticipantStoreInterface
{
    /** @return ChannelParticipant[] */
    public function findByChannel(Channel $channel): array;

    public function findOneByUserAndChannel(User $user, Channel $channel): ?ChannelParticipant;

    /** @return int[] */
    public function findChannelIdsByUser(User $user): array;

    /**
     * @param int[] $userIds
     *
     * @return int[] subset of $userIds currently connected to at least one voice room
     */
    public function findActiveUserIds(array $userIds): array;

    public function save(User $user, Channel $channel, string $livekitIdentity): void;

    public function remove(User $user, Channel $channel): void;

    public function clear(Channel $channel): void;

    public function refreshTtl(Channel $channel): void;
}
