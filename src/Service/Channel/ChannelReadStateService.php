<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Entity\Channel;
use App\Entity\ChannelReadState;
use App\Entity\Community;
use App\Entity\User;
use App\Repository\ChannelReadStateRepository;
use App\Repository\ChannelRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Security\Voter\CommunityVoter;
use App\Service\AbstractDoctrineService;

class ChannelReadStateService extends AbstractDoctrineService implements ChannelReadStateServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly ChannelReadStateRepository $channelReadStateRepository,
        private readonly ChannelRepository $channelRepository,
        private readonly ChannelServiceInterface $channelService,
    ) {
    }

    #[\Override]
    public function findReadState(Channel $channel, User $user): ?ChannelReadState
    {
        return $this->channelReadStateRepository->findForUser($channel, $user);
    }

    #[\Override]
    public function findLastReadAtForUsers(Channel $channel, array $userIds): array
    {
        return $this->channelReadStateRepository->findLastReadAtByUserIds($channel, $userIds);
    }

    #[\Override]
    public function markRead(Channel $channel): ChannelReadState
    {
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You cannot access this channel.');
        $caller = $this->security->currentUser('You must be signed in to mark a channel as read.');

        $readState = $this->channelReadStateRepository->findForUser($channel, $caller)
            ?? (new ChannelReadState())->setUser($caller)->setChannel($channel);
        $readState->setLastReadAt(new \DateTimeImmutable());

        return $this->save($readState);
    }

    #[\Override]
    public function getUnreadChannelIdentifiers(Community $community): array
    {
        $caller = $this->security->currentUser('You must be signed in to read channel state.');

        return $this->channelReadStateRepository->findUnreadChannelIdentifiers($community, $caller);
    }

    #[\Override]
    public function markAllReadInCommunity(Community $community): void
    {
        $this->security->throwAccessDeniedUnlessGranted(CommunityVoter::VIEW, $community, 'You cannot access this community.');
        $caller = $this->security->currentUser('You must be signed in to mark channels as read.');

        $channels = array_values(array_filter(
            $this->channelRepository->findByCommunityWithCommunity($community),
            fn (Channel $channel): bool => $this->security->isGranted(ChannelVoter::VIEW, $channel),
        ));

        $this->channelReadStateRepository->upsertManyToNow($caller, $channels);
    }

    #[\Override]
    public function markAllReadEverywhere(): void
    {
        $caller = $this->security->currentUser('You must be signed in to mark channels as read.');

        $this->channelReadStateRepository->upsertManyToNow($caller, $this->channelService->getViewableWithCommunity());
    }
}
