<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Dto\ChannelSection\CreateChannelSectionDto;
use App\Dto\ChannelSection\UpdateChannelSectionDto;
use App\Entity\ChannelSection;
use App\Entity\Community;

interface ChannelSectionServiceInterface
{
    public function new(Community $community, CreateChannelSectionDto $createChannelSectionDto): ChannelSection;

    public function update(ChannelSection $channelSection, UpdateChannelSectionDto $updateChannelSectionDto): ChannelSection;

    public function delete(ChannelSection $channelSection): void;

    public function get(int $id): ChannelSection;

    public function getByIdentifier(string $identifier, Community $community): ChannelSection;

    /**
     * @return ChannelSection[]
     */
    public function getAllForCommunity(Community $community): array;

    /**
     * @param list<int> $orderedIds
     */
    public function reorderSections(Community $community, array $orderedIds): void;

    /**
     * @param list<int> $orderedIds
     */
    public function reorderChannels(ChannelSection $section, array $orderedIds): void;
}
