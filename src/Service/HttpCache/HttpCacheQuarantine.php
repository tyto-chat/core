<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use Predis\ClientInterface;

final readonly class HttpCacheQuarantine implements HttpCacheQuarantineInterface
{
    public const int WINDOW_SECONDS = 120;

    public function __construct(
        private ClientInterface $redis,
    ) {
    }

    #[\Override]
    public function quarantineCommunity(int $communityId): void
    {
        $this->redis->set(self::key($communityId), '1', 'EX', self::WINDOW_SECONDS);
    }

    #[\Override]
    public function isCommunityQuarantined(int $communityId): bool
    {
        return $this->isSet(self::key($communityId));
    }

    #[\Override]
    public function quarantineUser(int $userId): void
    {
        $this->redis->set(self::userKey($userId), '1', 'EX', self::WINDOW_SECONDS);
    }

    #[\Override]
    public function isUserQuarantined(int $userId): bool
    {
        return $this->isSet(self::userKey($userId));
    }

    public static function key(int $communityId): string
    {
        return 'http_cache:quarantine:k'.$communityId;
    }

    public static function userKey(int $userId): string
    {
        return 'http_cache:quarantine:u'.$userId;
    }

    private function isSet(string $key): bool
    {
        try {
            return (int) $this->redis->exists($key) > 0;
        } catch (\Throwable) {
            return true;
        }
    }
}
