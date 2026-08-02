<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Dto\Channel\CreateChannelDto;
use App\Dto\Channel\UpdateChannelDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MessagePage;
use App\Enum\Channel\ChannelType;
use App\Exception\Channel\CannotArchiveAudioChannelException;
use App\Exception\Channel\CannotArchiveWelcomeChannelException;
use App\Exception\Channel\ChannelAlreadyArchivedException;
use App\Exception\Channel\ChannelNotArchivedException;
use App\Exception\Channel\ChannelNotFoundException;
use App\Exception\Channel\InvalidChannelSectionException;
use App\Exception\Channel\VoiceDisabledException;
use App\Exception\Message\MessagePageNotFoundException;
use App\Repository\ChannelRepository;
use App\Repository\MessagePageRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Service\AbstractDoctrineService;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Message\MessageServiceInterface;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use App\Service\Search\SearchServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Voice\ChannelParticipantStoreInterface;
use App\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Lazy;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ChannelService extends AbstractDoctrineService implements ChannelServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly ChannelRepository $channelRepository,
        private readonly MessagePageRepository $messagePageRepository,
        #[Lazy]
        private readonly MessageServiceInterface $messageService,
        private readonly bool $voiceEnabled,
        private readonly StructureRealtimePublisherInterface $realtimePublisher,
        private readonly MessageBusInterface $messageBus,
        private readonly ChannelParticipantStoreInterface $participantStore,
        private readonly SearchServiceInterface $searchService,
        private readonly SettingsServiceInterface $settings,
        private readonly MediaObjectServiceInterface $mediaObjectService,
    ) {
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function new(CreateChannelDto $createChannelDto): Channel
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($createChannelDto->community, "You don't have permission to create channels.");

        if (!$this->voiceEnabled && ChannelType::Audio === $createChannelDto->type) {
            throw new VoiceDisabledException('Voice is disabled on this server; audio channels cannot be created.');
        }

        $channel = new Channel();
        if (null !== $createChannelDto->section) {
            $channel->setPosition($this->channelRepository->findMaxPositionInSection($createChannelDto->section) + 1);
        }

        $saved = $this->save($channel, $createChannelDto);
        $this->realtimePublisher->publishCommunityStructureChanged($createChannelDto->community);

        return $saved;
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function update(Channel $channel, UpdateChannelDto $updateChannelDto): Channel
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($channel->getCommunity(), "You don't have permission to update channels.");

        if ($updateChannelDto->isProvided('section')
            && (null === $updateChannelDto->section
                || $updateChannelDto->section->getCommunity() !== $channel->getCommunity())) {
            throw new InvalidChannelSectionException();
        }

        $updated = $this->save($channel, $updateChannelDto);

        $community = $updated->getCommunity();
        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
        }

        return $updated;
    }

    #[\Override]
    public function find(int $id): ?Channel
    {
        return $this->channelRepository->find($id);
    }

    #[\Override]
    public function countPublicActivePerCommunity(): array
    {
        return $this->channelRepository->countPublicActivePerCommunity();
    }

    #[\Override]
    public function get(int $id): Channel
    {
        return $this->getByCriteria(['id' => $id]);
    }

    #[\Override]
    public function getByIdentifier(string $identifier, Community $community): Channel
    {
        return $this->getByCriteria(['identifier' => $identifier, 'community' => $community]);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @throws AccessDeniedException
     * @throws ChannelNotFoundException
     */
    private function getByCriteria(array $criteria): Channel
    {
        $channel = $this->channelRepository->findOneBy($criteria);
        if (null === $channel) {
            throw new ChannelNotFoundException(sprintf('Channel not found using criteria: %s', json_encode($criteria, \JSON_THROW_ON_ERROR)));
        }

        if (!$this->security->isGranted(ChannelVoter::VIEW, $channel)) {
            // Anonymous → 401; authenticated denial → 404 (no existence leak).
            $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view this channel.');

            throw new ChannelNotFoundException(sprintf('Channel not found using criteria: %s', json_encode($criteria, \JSON_THROW_ON_ERROR)));
        }

        return $channel;
    }

    #[\Override]
    public function delete(Channel $channel): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($channel->getCommunity(), "You don't have permission to delete channels.");
        $this->doDelete($channel);
    }

    private function doDelete(Channel $channel): void
    {
        $community = $channel->getCommunity();
        $channelId = (int) $channel->getId();
        foreach ($this->participantStore->findByChannel($channel) as $participant) {
            $this->messageBus->dispatch(new DisconnectVoiceParticipantMessage(
                (int) $participant->getUser()->getId(),
                channelId: $channelId,
            ));
        }

        $this->mediaObjectService->deleteChannelAttachments($channel);
        $this->removeAndFlush($channel);
        $this->searchService->removeChannelDocuments($channelId);
        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
        }
    }

    #[\Override]
    public function purgeExpiredArchived(): int
    {
        $days = $this->settings->get(Settings::archivedChannelRetentionDays());
        if ($days <= 0) {
            return 0;
        }
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));
        $expired = $this->channelRepository->findArchivedBefore($cutoff);
        foreach ($expired as $channel) {
            $this->doDelete($channel);
        }

        return count($expired);
    }

    #[\Override]
    public function archive(Channel $channel): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($channel->getCommunity(), "You don't have permission to archive channels.");

        if (ChannelType::Audio === $channel->getType()) {
            throw new CannotArchiveAudioChannelException();
        }
        if ($channel->isArchived()) {
            throw new ChannelAlreadyArchivedException();
        }
        $community = $channel->getCommunity();
        if (null !== $community && $community->getWelcomeChannel()?->getId() === $channel->getId()) {
            throw new CannotArchiveWelcomeChannelException();
        }

        $channel->setArchivedAt(new \DateTimeImmutable());
        $this->save($channel);

        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
        }
    }

    #[\Override]
    public function unarchive(Channel $channel): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($channel->getCommunity(), "You don't have permission to unarchive channels.");

        if (!$channel->isArchived()) {
            throw new ChannelNotArchivedException();
        }

        $channel->setArchivedAt(null);
        $this->save($channel);

        $community = $channel->getCommunity();
        if (null !== $community) {
            $this->realtimePublisher->publishCommunityStructureChanged($community);
        }
    }

    #[\Override]
    public function findLatestPage(Channel $channel): ?MessagePage
    {
        return $this->messagePageRepository->findLatestForChannel($channel);
    }

    /**
     * @return MessagePage[]
     */
    #[\Override]
    public function getChannelPages(Channel $channel): array
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You are not a member of this channel.');

        return $this->messagePageRepository->findAllForChannel($channel);
    }

    #[\Override]
    public function getChannelPage(Channel $channel, int $pageNumber): MessagePage
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You are not a member of this channel.');

        $page = $this->messagePageRepository->findByChannelAndPageNumber($channel, $pageNumber);
        if (null === $page) {
            throw new MessagePageNotFoundException(sprintf('Page %d not found for channel %d.', $pageNumber, $channel->getId()));
        }

        return $this->hydratePage($page);
    }

    #[\Override]
    public function getCurrentPage(Channel $channel): MessagePage
    {
        $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to read messages.');
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You are not a member of this channel.');

        $page = $this->findLatestPage($channel);
        if (null === $page) {
            // A channel with no messages has no page row yet. The response is a
            // MessagePage resource, so it needs a real, IRI-able one — an
            // unsaved placeholder cannot be queried with or serialised, which
            // made every freshly created channel fail on first open. Creating it
            // here is idempotent: the next read finds this row.
            $page = (new MessagePage())->setChannel($channel);
            $this->save($page);
        }

        return $this->hydratePage($page);
    }

    private function hydratePage(MessagePage $page): MessagePage
    {
        $messages = $this->messageService->getRootsByPage($page);
        foreach ($messages as $message) {
            $this->messageService->hydrateText($message);
        }
        $page->setHydratedMessages($messages);

        return $page;
    }

    #[\Override]
    public function nextPageNumber(Channel $channel): int
    {
        return $this->messagePageRepository->nextPageNumberForChannel($channel);
    }

    /** @return Channel[] */
    #[\Override]
    public function getAllWithCommunity(): array
    {
        return $this->channelRepository->findAllWithCommunity();
    }

    /**
     * @return Channel[]
     */
    #[\Override]
    public function getViewableWithCommunity(): array
    {
        if ($this->security->isAdmin()) {
            return $this->channelRepository->findAllWithCommunity();
        }

        $user = $this->security->getUser();
        if (null === $user) {
            return $this->voterFilterView($this->channelRepository->findAllWithCommunity());
        }

        // SQL yields a possible-VIEW superset; the voter filter is the real gate.
        return $this->voterFilterView($this->channelRepository->findCandidateViewableForUser($user));
    }

    /**
     * @param Channel[] $channels
     *
     * @return Channel[]
     */
    private function voterFilterView(array $channels): array
    {
        return array_values(array_filter(
            $channels,
            fn (Channel $channel): bool => $this->security->isGranted(ChannelVoter::VIEW, $channel),
        ));
    }

    /** @return Channel[] */
    #[\Override]
    public function getPublicTextChannels(): array
    {
        return $this->channelRepository->findPublicTextChannels();
    }

    /** @return Channel[] */
    #[\Override]
    public function getViewableTextChannels(Community $community): array
    {
        $text = array_filter(
            $this->channelRepository->findByCommunityWithCommunity($community),
            static fn (Channel $channel): bool => ChannelType::Text === $channel->getType(),
        );

        return $this->voterFilterView(array_values($text));
    }
}
