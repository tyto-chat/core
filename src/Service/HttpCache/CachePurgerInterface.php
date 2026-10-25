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

    public function purgeUserContext(int $userId): void;

    public function purgeCommunityContext(int $communityId): void;

    public function purgeAuthor(int $userId): void;

    public function purgeCommunityPages(int $communityId): void;

    /** @internal Second, delayed purge of a permission tag: drops entries an in-flight request stored after the first purge. */
    public function repurgeTag(string $tag): void;
}
