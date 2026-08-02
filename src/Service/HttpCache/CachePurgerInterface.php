<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

interface CachePurgerInterface
{
    public function purgeChannelPage(string $communityIdentifier, string $channelIdentifier, int $pageNumber): void;

    public function purgeChannelExtras(string $communityIdentifier, string $channelIdentifier): void;

    public function purgeCommunityEmojis(string $communityIdentifier): void;

    public function purgeMessageThread(string $messageUuid): void;

    public function purgeMessage(string $messageUuid): void;

    public function purgeCommunityDetail(string $communityIdentifier): void;
}
