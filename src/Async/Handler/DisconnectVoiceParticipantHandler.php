<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DisconnectVoiceParticipantHandler
{
    public function __construct(
        private readonly UserServiceInterface $userService,
        private readonly ChannelServiceInterface $channelService,
        private readonly CommunityServiceInterface $communityService,
        private readonly ChannelAudioServiceInterface $channelAudioService,
    ) {
    }

    public function __invoke(DisconnectVoiceParticipantMessage $message): void
    {
        $user = $this->userService->find($message->userId);
        if (null === $user) {
            return;
        }

        if (null !== $message->channelId) {
            $channel = $this->channelService->find($message->channelId);
            if (null !== $channel) {
                $this->channelAudioService->disconnectFromChannel($user, $channel);
            }

            return;
        }

        if (null !== $message->communityIdentifier) {
            $community = $this->communityService->findByIdentifier($message->communityIdentifier);
            if (null !== $community) {
                $this->channelAudioService->disconnectFromCommunity($user, $community);
            }

            return;
        }

        $this->channelAudioService->disconnectFromAllRooms($user);
    }
}
