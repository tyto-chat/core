<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Entity\Channel;
use App\Entity\ChannelReadState;
use App\Entity\Community;
use App\Entity\User;

interface ChannelReadStateServiceInterface
{
    public function markRead(Channel $channel): ChannelReadState;

    public function findReadState(Channel $channel, User $user): ?ChannelReadState;

    /**
     * @param int[] $userIds
     *
     * @return array<int, \DateTimeImmutable> userId => lastReadAt
     */
    public function findLastReadAtForUsers(Channel $channel, array $userIds): array;

    public function markAllReadInCommunity(Community $community): void;

    public function markAllReadEverywhere(): void;

    /**
     * @return string[]
     */
    public function getUnreadChannelIdentifiers(Community $community): array;
}
