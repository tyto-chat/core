<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Service\HttpCache\HttpCacheQuarantineInterface;

final class InMemoryHttpCacheQuarantine implements HttpCacheQuarantineInterface
{
    /** @var array<int, true> */
    private array $quarantined = [];

    /** @var array<int, true> */
    private array $quarantinedUsers = [];

    #[\Override]
    public function quarantineCommunity(int $communityId): void
    {
        $this->quarantined[$communityId] = true;
    }

    #[\Override]
    public function isCommunityQuarantined(int $communityId): bool
    {
        return isset($this->quarantined[$communityId]);
    }

    #[\Override]
    public function quarantineUser(int $userId): void
    {
        $this->quarantinedUsers[$userId] = true;
    }

    #[\Override]
    public function isUserQuarantined(int $userId): bool
    {
        return isset($this->quarantinedUsers[$userId]);
    }
}
