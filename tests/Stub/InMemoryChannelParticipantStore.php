<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Entity\Channel;
use App\Entity\ChannelParticipant;
use App\Entity\User;
use App\Service\Voice\ChannelParticipantStoreInterface;

final class InMemoryChannelParticipantStore implements ChannelParticipantStoreInterface
{
    /** @var array<int, array<int, array{user: User, channel: Channel, identity: string, joinedAt: \DateTimeImmutable}>> */
    private array $rows = [];

    public function findByChannel(Channel $channel): array
    {
        $channelId = $channel->getId();
        if (null === $channelId) {
            return [];
        }

        $rows = array_values($this->rows[$channelId] ?? []);
        usort($rows, static fn (array $a, array $b): int => $a['joinedAt'] <=> $b['joinedAt']);

        return array_map(
            static fn (array $row): ChannelParticipant => new ChannelParticipant(
                $row['user'],
                $row['channel'],
                $row['identity'],
                $row['joinedAt'],
            ),
            $rows,
        );
    }

    public function findOneByUserAndChannel(User $user, Channel $channel): ?ChannelParticipant
    {
        $row = $this->rows[$channel->getId()][$user->getId()] ?? null;

        return null === $row
            ? null
            : new ChannelParticipant($row['user'], $row['channel'], $row['identity'], $row['joinedAt']);
    }

    public function findChannelIdsByUser(User $user): array
    {
        $userId = $user->getId();
        $channelIds = [];
        foreach ($this->rows as $channelId => $users) {
            if (isset($users[$userId])) {
                $channelIds[] = $channelId;
            }
        }

        return $channelIds;
    }

    public function findActiveUserIds(array $userIds): array
    {
        $active = [];
        foreach ($userIds as $userId) {
            foreach ($this->rows as $users) {
                if (isset($users[$userId])) {
                    $active[] = $userId;
                    break;
                }
            }
        }

        return $active;
    }

    public function save(User $user, Channel $channel, string $livekitIdentity): void
    {
        $userId = $user->getId();
        $channelId = $channel->getId();
        if (null === $userId || null === $channelId) {
            return;
        }

        $this->rows[$channelId][$userId] = [
            'user' => $user,
            'channel' => $channel,
            'identity' => $livekitIdentity,
            'joinedAt' => $this->rows[$channelId][$userId]['joinedAt'] ?? new \DateTimeImmutable(),
        ];
    }

    public function remove(User $user, Channel $channel): void
    {
        unset($this->rows[$channel->getId()][$user->getId()]);
    }

    public function clear(Channel $channel): void
    {
        unset($this->rows[$channel->getId()]);
    }

    public function refreshTtl(Channel $channel): void
    {
    }
}
