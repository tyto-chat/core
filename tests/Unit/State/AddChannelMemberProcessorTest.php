<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\Dto\Channel\AddChannelMemberDto;
use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\User\UserServiceInterface;
use App\State\Channel\Processor\AddChannelMemberProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AddChannelMemberProcessorTest extends TestCase
{
    private UserServiceInterface&MockObject $userService;
    private ChannelServiceInterface&MockObject $channelService;
    private ChannelMembershipServiceInterface&MockObject $channelMembershipService;
    private CommunityServiceInterface&MockObject $communityService;
    private AddChannelMemberProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->userService = $this->createMock(UserServiceInterface::class);
        $this->channelService = $this->createMock(ChannelServiceInterface::class);
        $this->channelMembershipService = $this->createMock(ChannelMembershipServiceInterface::class);
        $this->communityService = $this->createMock(CommunityServiceInterface::class);
        $this->processor = new AddChannelMemberProcessor(
            $this->userService,
            $this->channelService,
            $this->channelMembershipService,
            $this->communityService,
        );
    }

    public function testProcessResolvesAndDelegatesToService(): void
    {
        $community = $this->createMock(Community::class);
        $channel = $this->createMock(Channel::class);
        $user = $this->createMock(User::class);
        $member = $this->createMock(ChannelMember::class);

        $this->communityService->method('getByIdentifier')->willReturn($community);
        $this->channelService->method('getByIdentifier')->willReturn($channel);
        $this->userService->method('get')->willReturn($user);
        $this->channelMembershipService->expects(self::once())
            ->method('addMember')
            ->with($channel, $user, ChannelRole::Moderator)
            ->willReturn($member);

        $dto = new AddChannelMemberDto(userId: 42, role: ChannelRole::Moderator);

        $result = $this->processor->process($dto, new Post(), ['community' => 'c1', 'channel' => 'ch1']);

        self::assertSame($member, $result);
    }
}
