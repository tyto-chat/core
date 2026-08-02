<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Exception\Channel\NotAnAudioChannelException;
use App\Exception\Channel\VoiceDisabledException;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Service\Presence\PresenceServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\User\UserServiceInterface;
use App\Service\Voice\ChannelParticipantStoreInterface;
use App\Service\Voice\VoiceServiceInterface;
use App\Utils\VoiceIdentity;

class ChannelAudioService implements ChannelAudioServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly ChannelParticipantStoreInterface $participantStore,
        private readonly RealtimePublisherInterface $publisher,
        private readonly VoiceServiceInterface $voiceService,
        private readonly PresenceServiceInterface $presenceService,
        private readonly bool $voiceEnabled,
        private readonly UserServiceInterface $userService,
        private readonly ChannelServiceInterface $channelService,
    ) {
    }

    #[\Override]
    public function getParticipants(Channel $channel): array
    {
        $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view channel participants.');
        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You do not have permission to view channel participants.');

        return $this->participantStore->findByChannel($channel);
    }

    #[\Override]
    public function joinAudioChannelAsCurrentUser(Channel $channel): void
    {
        $user = $this->security->currentUser('You must be signed in to join audio channels.');

        $this->security->throwAccessDeniedUnlessGranted(ChannelVoter::VIEW, $channel, 'You do not have permission to join this channel.');

        $this->joinAudioChannel($user, $channel);
    }

    #[\Override]
    public function joinAudioChannel(User $user, Channel $channel): void
    {
        if (!$this->voiceEnabled) {
            throw new VoiceDisabledException('Voice is disabled on this server.');
        }

        if (ChannelType::Audio !== $channel->getType()) {
            throw new NotAnAudioChannelException('Channel is not an audio channel.');
        }

        $this->participantStore->save($user, $channel, VoiceIdentity::user($user));

        $this->publishAudioChannelParticipants($channel);
        $this->presenceService->reevaluate($user);
    }

    #[\Override]
    public function leaveAudioChannel(User $user, Channel $channel): void
    {
        if (null === $this->participantStore->findOneByUserAndChannel($user, $channel)) {
            return;
        }

        $this->participantStore->remove($user, $channel);
        $this->publishAudioChannelParticipants($channel);
        $this->presenceService->reevaluate($user);
    }

    #[\Override]
    public function publishAudioChannelParticipants(Channel $channel): void
    {
        $this->publisher->publishAudioChannelParticipants($channel);
    }

    #[\Override]
    public function filterActiveVoiceUserIds(array $userIds): array
    {
        return $this->participantStore->findActiveUserIds($userIds);
    }

    #[\Override]
    public function disconnectFromChannel(User $user, Channel $channel): void
    {
        $this->kickFromRoom($user, $channel);
    }

    #[\Override]
    public function disconnectFromCommunity(User $user, Community $community): void
    {
        foreach ($this->participantStore->findChannelIdsByUser($user) as $channelId) {
            $channel = $this->channelService->find($channelId);
            if (null !== $channel && $channel->getCommunity()?->getId() === $community->getId()) {
                $this->kickFromRoom($user, $channel);
            }
        }
    }

    #[\Override]
    public function disconnectFromAllRooms(User $user): void
    {
        foreach ($this->participantStore->findChannelIdsByUser($user) as $channelId) {
            $channel = $this->channelService->find($channelId);
            if (null !== $channel) {
                $this->kickFromRoom($user, $channel);
            }
        }
    }

    private function kickFromRoom(User $user, Channel $channel): void
    {
        $this->voiceService->removeParticipant(
            VoiceIdentity::room($channel),
            VoiceIdentity::user($user),
        );
        $this->leaveAudioChannel($user, $channel);
    }

    #[\Override]
    public function reconcileAudioParticipants(Channel $channel): int
    {
        $roomName = VoiceIdentity::room($channel);
        $liveIdentities = $this->voiceService->listParticipantIdentities($roomName);

        $changed = 0;
        $known = [];
        $affected = [];
        foreach ($this->participantStore->findByChannel($channel) as $participant) {
            $known[$participant->getLivekitIdentity()] = true;
            if (!in_array($participant->getLivekitIdentity(), $liveIdentities, true)) {
                $affected[] = $participant->getUser();
                $this->participantStore->remove($participant->getUser(), $channel);
                ++$changed;
            }
        }

        foreach ($liveIdentities as $identity) {
            $userId = VoiceIdentity::userIdFrom($identity);
            if (isset($known[$identity]) || null === $userId) {
                continue;
            }
            $user = $this->userService->find($userId);
            if (null === $user) {
                continue;
            }
            $this->participantStore->save($user, $channel, $identity);
            $affected[] = $user;
            ++$changed;
        }

        // Unconditional: a quiet room would otherwise let its keys lapse between reconciles.
        $this->participantStore->refreshTtl($channel);

        if ($changed > 0) {
            $this->publishAudioChannelParticipants($channel);
            $this->reevaluatePresence($affected);
        }

        return $changed;
    }

    #[\Override]
    public function handleRoomFinished(Channel $channel): void
    {
        $participants = $this->participantStore->findByChannel($channel);
        if ([] === $participants) {
            return;
        }

        $affected = [];
        foreach ($participants as $participant) {
            $affected[] = $participant->getUser();
        }

        $this->participantStore->clear($channel);
        $this->publishAudioChannelParticipants($channel);
        $this->reevaluatePresence($affected);
    }

    /** @param list<?User> $users */
    private function reevaluatePresence(array $users): void
    {
        $seen = [];
        foreach ($users as $user) {
            $id = $user?->getId();
            if (null === $id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->presenceService->reevaluate($user);
        }
    }

    #[\Override]
    public function reconcileActiveRooms(): void
    {
        foreach ($this->voiceService->listActiveRooms() as $roomName) {
            $channelId = VoiceIdentity::channelIdFrom($roomName);
            if (null === $channelId) {
                continue;
            }
            $channel = $this->channelService->find($channelId);
            if (null === $channel || ChannelType::Audio !== $channel->getType()) {
                continue;
            }
            $this->reconcileAudioParticipants($channel);
        }
    }
}
