<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Dto\Channel\CreateChannelDto;
use App\Dto\Channel\UpdateChannelDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MessagePage;

interface ChannelServiceInterface
{
    public function new(CreateChannelDto $createChannelDto): Channel;

    public function update(Channel $channel, UpdateChannelDto $updateChannelDto): Channel;

    /** @internal No authz — caller must gate. Use get()/getByIdentifier() for gated reads. */
    public function find(int $id): ?Channel;

    public function get(int $id): Channel;

    /** @return array<int, int> community id → count of public, non-archived channels */
    public function countPublicActivePerCommunity(): array;

    public function getByIdentifier(string $identifier, Community $community): Channel;

    public function delete(Channel $channel): void;

    /** @internal No authz — scheduler-only. */
    public function purgeExpiredArchived(): int;

    public function archive(Channel $channel): void;

    public function unarchive(Channel $channel): void;

    /**
     * @return MessagePage[]
     */
    public function getChannelPages(Channel $channel): array;

    public function getChannelPage(Channel $channel, int $pageNumber): MessagePage;

    public function getCurrentPage(Channel $channel): MessagePage;

    /** @internal No authz — caller must gate. Consumed by MessageService. */
    public function findLatestPage(Channel $channel): ?MessagePage;

    public function nextPageNumber(Channel $channel): int;

    /** @return Channel[] */
    public function getAllWithCommunity(): array;

    /**
     * @return Channel[]
     */
    public function getViewableWithCommunity(): array;

    /** @return Channel[] */
    public function getPublicTextChannels(): array;

    /**
     * @return Channel[]
     */
    public function getViewableTextChannels(Community $community): array;
}
