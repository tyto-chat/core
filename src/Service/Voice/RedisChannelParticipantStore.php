<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Channel;
use App\Entity\ChannelParticipant;
use App\Entity\User;
use App\Service\User\UserServiceInterface;
use Predis\ClientInterface;
use Symfony\Contracts\Service\Attribute\Required;

final class RedisChannelParticipantStore implements ChannelParticipantStoreInterface
{
    private const TTL = 3600;

    private UserServiceInterface $userService;

    public function __construct(
        private readonly ClientInterface $redis,
    ) {
    }

    #[Required]
    public function setUserService(UserServiceInterface $userService): void
    {
        $this->userService = $userService;
    }

    #[\Override]
    public function findByChannel(Channel $channel): array
    {
        $channelId = $channel->getId();
        if (null === $channelId) {
            return [];
        }

        /** @var array<string, string> $raw */
        $raw = $this->redis->hgetall(self::channelKey($channelId));
        if ([] === $raw) {
            return [];
        }

        $users = [];
        foreach ($this->userService->findByIds(array_map(intval(...), array_keys($raw))) as $user) {
            $users[(int) $user->getId()] = $user;
        }

        $participants = [];
        foreach ($raw as $userId => $encoded) {
            $user = $users[(int) $userId] ?? null;
            $entry = self::decodeEntry($encoded);
            if (null === $user || null === $entry) {
                continue;
            }
            $participants[] = new ChannelParticipant($user, $channel, $entry['identity'], $entry['joinedAt']);
        }

        usort(
            $participants,
            static fn (ChannelParticipant $a, ChannelParticipant $b): int => $a->getJoinedAt() <=> $b->getJoinedAt(),
        );

        return $participants;
    }

    #[\Override]
    public function findOneByUserAndChannel(User $user, Channel $channel): ?ChannelParticipant
    {
        $userId = $user->getId();
        $channelId = $channel->getId();
        if (null === $userId || null === $channelId) {
            return null;
        }

        $encoded = $this->redis->hget(self::channelKey($channelId), (string) $userId);
        $entry = self::decodeEntry(\is_string($encoded) ? $encoded : null);

        return null === $entry
            ? null
            : new ChannelParticipant($user, $channel, $entry['identity'], $entry['joinedAt']);
    }

    #[\Override]
    public function findChannelIdsByUser(User $user): array
    {
        $userId = $user->getId();
        if (null === $userId) {
            return [];
        }

        $encoded = $this->redis->get(self::userKey($userId));

        return self::decodeChannelIds(\is_string($encoded) ? $encoded : null);
    }

    #[\Override]
    public function findActiveUserIds(array $userIds): array
    {
        $userIds = array_values($userIds);
        if ([] === $userIds) {
            return [];
        }

        /** @var list<?string> $values */
        $values = $this->redis->mget(array_map(static fn (int $id): string => self::userKey($id), $userIds));

        $active = [];
        foreach ($userIds as $i => $userId) {
            if ([] !== self::decodeChannelIds($values[$i] ?? null)) {
                $active[] = $userId;
            }
        }

        return $active;
    }

    #[\Override]
    public function save(User $user, Channel $channel, string $livekitIdentity): void
    {
        $userId = $user->getId();
        $channelId = $channel->getId();
        if (null === $userId || null === $channelId) {
            return;
        }

        $channelKey = self::channelKey($channelId);
        $existing = $this->redis->hget($channelKey, (string) $userId);
        $entry = self::decodeEntry(\is_string($existing) ? $existing : null);
        $joinedAt = $entry['joinedAt'] ?? new \DateTimeImmutable();

        $this->redis->hset($channelKey, (string) $userId, json_encode([
            'identity' => $livekitIdentity,
            'joinedAt' => $joinedAt->format(\DateTimeInterface::ATOM),
        ], \JSON_THROW_ON_ERROR));
        $this->redis->expire($channelKey, self::TTL);

        $channelIds = $this->findChannelIdsByUser($user);
        if (!\in_array($channelId, $channelIds, true)) {
            $channelIds[] = $channelId;
        }
        $this->writeUserIndex($userId, $channelIds);
    }

    #[\Override]
    public function remove(User $user, Channel $channel): void
    {
        $userId = $user->getId();
        $channelId = $channel->getId();
        if (null === $userId || null === $channelId) {
            return;
        }

        $this->redis->hdel(self::channelKey($channelId), [(string) $userId]);
        $this->writeUserIndex(
            $userId,
            array_values(array_filter(
                $this->findChannelIdsByUser($user),
                static fn (int $id): bool => $id !== $channelId,
            )),
        );
    }

    #[\Override]
    public function clear(Channel $channel): void
    {
        $channelId = $channel->getId();
        if (null === $channelId) {
            return;
        }

        $channelKey = self::channelKey($channelId);
        /** @var array<string, string> $raw */
        $raw = $this->redis->hgetall($channelKey);
        $this->redis->del([$channelKey]);

        foreach (array_keys($raw) as $rawUserId) {
            $userId = (int) $rawUserId;
            $encoded = $this->redis->get(self::userKey($userId));
            $this->writeUserIndex($userId, array_values(array_filter(
                self::decodeChannelIds(\is_string($encoded) ? $encoded : null),
                static fn (int $id): bool => $id !== $channelId,
            )));
        }
    }

    #[\Override]
    public function refreshTtl(Channel $channel): void
    {
        $channelId = $channel->getId();
        if (null === $channelId) {
            return;
        }

        $channelKey = self::channelKey($channelId);
        /** @var array<string, string> $raw */
        $raw = $this->redis->hgetall($channelKey);
        if ([] === $raw) {
            return;
        }

        $this->redis->expire($channelKey, self::TTL);
        foreach (array_keys($raw) as $userId) {
            $this->redis->expire(self::userKey((int) $userId), self::TTL);
        }
    }

    /** @param int[] $channelIds */
    private function writeUserIndex(int $userId, array $channelIds): void
    {
        if ([] === $channelIds) {
            $this->redis->del([self::userKey($userId)]);

            return;
        }

        $this->redis->setex(self::userKey($userId), self::TTL, json_encode($channelIds, \JSON_THROW_ON_ERROR));
    }

    /** @return array{identity: string, joinedAt: \DateTimeImmutable}|null */
    private static function decodeEntry(?string $encoded): ?array
    {
        if (null === $encoded || '' === $encoded) {
            return null;
        }

        $decoded = json_decode($encoded, true);
        if (!\is_array($decoded) || !\is_string($decoded['identity'] ?? null) || !\is_string($decoded['joinedAt'] ?? null)) {
            return null;
        }

        try {
            $joinedAt = new \DateTimeImmutable($decoded['joinedAt']);
        } catch (\Exception) {
            return null;
        }

        return ['identity' => $decoded['identity'], 'joinedAt' => $joinedAt];
    }

    /** @return int[] */
    private static function decodeChannelIds(?string $encoded): array
    {
        if (null === $encoded || '' === $encoded) {
            return [];
        }

        $decoded = json_decode($encoded, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_map(intval(...), array_filter($decoded, is_numeric(...))));
    }

    private static function channelKey(int $channelId): string
    {
        return 'voice:channel:'.$channelId;
    }

    private static function userKey(int $userId): string
    {
        return 'voice:user:'.$userId;
    }
}
