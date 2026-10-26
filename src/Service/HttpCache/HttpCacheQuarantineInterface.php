<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

interface HttpCacheQuarantineInterface
{
    /** @internal No authz — stops caching a community's pages and permission hashes for a while after its permissions changed. */
    public function quarantineCommunity(int $communityId): void;

    public function isCommunityQuarantined(int $communityId): bool;

    /** @internal No authz — stops caching a user's permission hash for a while after their permissions changed. */
    public function quarantineUser(int $userId): void;

    public function isUserQuarantined(int $userId): bool;
}
