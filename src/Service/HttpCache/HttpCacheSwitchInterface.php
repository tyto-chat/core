<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

interface HttpCacheSwitchInterface
{
    public function isOn(): bool;

    public function isPresenceOn(): bool;

    /** @internal No authz — mirrors the TTL settings into the flag file Caddy reads. */
    public function sync(): bool;
}
