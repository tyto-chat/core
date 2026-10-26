<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

interface HttpCacheFlusherInterface
{
    /** @internal No authz — deletes every stored edge-cache entry; returns the number of keys removed. */
    public function flushAll(): int;
}
