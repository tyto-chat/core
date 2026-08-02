<?php

declare(strict_types=1);

namespace App\Tests\Unit\Async;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Async\Handler\DisconnectVoiceParticipantHandler;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;
use PHPUnit\Framework\TestCase;

final class DisconnectVoiceParticipantHandlerTest extends TestCase
{
    public function testChannelScope(): void
    {
        $user = self::createStub(User::class);
        $channel = self::createStub(Channel::class);

        $userService = self::createStub(UserServiceInterface::class);
        $userService->method('find')->willReturn($user);

        $channelService = self::createStub(ChannelServiceInterface::class);
        $channelService->method('find')->willReturn($channel);

        $audio = $this->createMock(ChannelAudioServiceInterface::class);
        $audio->expects(self::once())->method('disconnectFromChannel')->with($user, $channel);
        $audio->expects(self::never())->method('disconnectFromAllRooms');

        (new DisconnectVoiceParticipantHandler(
            $userService,
            $channelService,
            self::createStub(CommunityServiceInterface::class),
            $audio,
        ))(new DisconnectVoiceParticipantMessage(7, channelId: 3));
    }

    public function testCommunityScope(): void
    {
        $user = self::createStub(User::class);
        $community = self::createStub(Community::class);

        $userService = self::createStub(UserServiceInterface::class);
        $userService->method('find')->willReturn($user);

        $communityService = self::createStub(CommunityServiceInterface::class);
        $communityService->method('findByIdentifier')->willReturn($community);

        $audio = $this->createMock(ChannelAudioServiceInterface::class);
        $audio->expects(self::once())->method('disconnectFromCommunity')->with($user, $community);

        (new DisconnectVoiceParticipantHandler(
            $userService,
            self::createStub(ChannelServiceInterface::class),
            $communityService,
            $audio,
        ))(new DisconnectVoiceParticipantMessage(7, communityIdentifier: 'c1'));
    }

    public function testAllRoomsScope(): void
    {
        $user = self::createStub(User::class);

        $userService = self::createStub(UserServiceInterface::class);
        $userService->method('find')->willReturn($user);

        $audio = $this->createMock(ChannelAudioServiceInterface::class);
        $audio->expects(self::once())->method('disconnectFromAllRooms')->with($user);

        (new DisconnectVoiceParticipantHandler(
            $userService,
            self::createStub(ChannelServiceInterface::class),
            self::createStub(CommunityServiceInterface::class),
            $audio,
        ))(new DisconnectVoiceParticipantMessage(7));
    }
}
