<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Entity\Community;

interface StructureRealtimePublisherInterface
{
    public function publishCommunityStructureChanged(Community $community): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function publishUserEvent(int $userId, string $event, array $payload = []): void;

    public function publishCommunityEmojisUpdated(Community $community): void;
}
