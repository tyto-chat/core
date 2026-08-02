<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Dto\ChannelSection\CreateChannelSectionDto;
use App\Dto\ChannelSection\UpdateChannelSectionDto;
use App\Entity\ChannelSection;
use App\Entity\Community;
use App\Exception\Channel\InvalidReorderException;
use App\Exception\ChannelSection\CannotDeleteNonEmptySectionException;
use App\Exception\ChannelSection\ChannelSectionNotFoundException;
use App\Repository\ChannelSectionRepository;
use App\Security\SecurityContext;
use App\Security\Voter\CommunityVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ChannelSectionService extends AbstractDoctrineService implements ChannelSectionServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private ChannelSectionRepository $channelSectionRepository,
        private StructureRealtimePublisherInterface $realtimePublisher,
    ) {
    }

    #[\Override]
    public function new(Community $community, CreateChannelSectionDto $createChannelSectionDto): ChannelSection
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, "You don't have permission to create channel sections.");
        $channelSection = new ChannelSection();
        $channelSection->setCommunity($community);
        $channelSection->setPosition($this->channelSectionRepository->findMaxPosition($community) + 1);

        $saved = $this->save($channelSection, $createChannelSectionDto);
        $this->realtimePublisher->publishCommunityStructureChanged($community);

        return $saved;
    }

    #[\Override]
    public function update(ChannelSection $channelSection, UpdateChannelSectionDto $updateChannelSectionDto): ChannelSection
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($channelSection->getCommunity(), "You don't have permission to update channel sections.");

        $saved = $this->save($channelSection, $updateChannelSectionDto);
        $community = $channelSection->getCommunity();
        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
        }

        return $saved;
    }

    #[\Override]
    public function delete(ChannelSection $channelSection): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($channelSection->getCommunity(), "You don't have permission to delete channel sections.");

        if (!$channelSection->getChannels()->isEmpty()) {
            throw new CannotDeleteNonEmptySectionException();
        }

        $community = $channelSection->getCommunity();
        $this->removeAndFlush($channelSection);
        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
        }
    }

    /**
     * @throws AccessDeniedException
     * @throws ChannelSectionNotFoundException
     */
    #[\Override]
    public function get(int $id): ChannelSection
    {
        return $this->getByCriteria(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private function getByCriteria(array $criteria): ChannelSection
    {
        $channelSection = $this->channelSectionRepository->findOneBy($criteria);
        if (null === $channelSection) {
            throw new ChannelSectionNotFoundException(sprintf('Channel section not found using criteria: %s', json_encode($criteria, \JSON_THROW_ON_ERROR)));
        }

        if (!$this->security->isGranted(CommunityVoter::VIEW, $channelSection->getCommunity())) {
            // Anonymous → 401; authenticated denial → 404 (no existence leak).
            $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view this section.');

            throw new ChannelSectionNotFoundException(sprintf('Channel section not found using criteria: %s', json_encode($criteria, \JSON_THROW_ON_ERROR)));
        }

        return $channelSection;
    }

    /**
     * @throws AccessDeniedException
     * @throws ChannelSectionNotFoundException
     */
    #[\Override]
    public function getByIdentifier(string $identifier, Community $community): ChannelSection
    {
        return $this->getByCriteria(['identifier' => $identifier, 'community' => $community]);
    }

    /**
     * @return ChannelSection[]
     *
     * @throws AccessDeniedException
     */
    #[\Override]
    public function getAllForCommunity(Community $community): array
    {
        $this->security->throwAccessDeniedUnlessGranted(
            CommunityVoter::VIEW,
            $community,
            'You are not a member of this community'
        );

        return $this->channelSectionRepository->findBy(['community' => $community]);
    }

    #[\Override]
    public function reorderSections(Community $community, array $orderedIds): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, "You don't have permission to reorder channel sections.");
        $sections = $this->channelSectionRepository->findBy(['community' => $community]);
        $byId = [];
        foreach ($sections as $section) {
            $byId[$section->getId()] = $section;
        }
        self::assertExactSet(array_keys($byId), $orderedIds);
        foreach (array_values($orderedIds) as $index => $id) {
            $byId[$id]->setPosition($index);
        }
        $this->flush();
        $this->realtimePublisher->publishCommunityStructureChanged($community);
    }

    #[\Override]
    public function reorderChannels(ChannelSection $section, array $orderedIds): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($section->getCommunity(), "You don't have permission to reorder channels.");
        $byId = [];
        foreach ($section->getChannels() as $channel) {
            $byId[$channel->getId()] = $channel;
        }
        self::assertExactSet(array_keys($byId), $orderedIds);
        foreach (array_values($orderedIds) as $index => $id) {
            $byId[$id]->setPosition($index);
        }
        $this->flush();
        $community = $section->getCommunity();
        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
        }
    }

    /**
     * @param array<int, int|string> $expected
     * @param array<int, int>        $submitted
     */
    private static function assertExactSet(array $expected, array $submitted): void
    {
        if (count($submitted) !== count($expected)
            || count(array_unique($submitted)) !== count($submitted)
            || [] !== array_diff($expected, $submitted)) {
            throw new InvalidReorderException('Reorder payload must list exactly the items being reordered.');
        }
    }
}
