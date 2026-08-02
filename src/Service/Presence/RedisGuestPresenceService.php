<?php

declare(strict_types=1);

namespace App\Service\Presence;

use App\Entity\Community;
use Predis\ClientInterface;

final readonly class RedisGuestPresenceService implements GuestPresenceServiceInterface
{
    private const WINDOW_SECONDS = 180;

    public function __construct(private ClientInterface $redis)
    {
    }

    public function touch(Community $community, string $visitorKey): void
    {
        $key = $this->key($community);
        $now = time();
        $this->redis->zadd($key, [$visitorKey => $now]);
        $this->redis->zremrangebyscore($key, '-inf', (string) ($now - self::WINDOW_SECONDS));
        $this->redis->expire($key, self::WINDOW_SECONDS * 2);
    }

    public function getGuestCount(Community $community): int
    {
        return (int) $this->redis->zcount(
            $this->key($community),
            (string) (time() - self::WINDOW_SECONDS),
            '+inf',
        );
    }

    private function key(Community $community): string
    {
        return sprintf('presence:guests:%d', $community->getId());
    }
}
