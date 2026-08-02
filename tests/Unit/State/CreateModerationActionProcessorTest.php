<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\Dto\Moderation\CreateModerationActionDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Enum\Moderation\ModerationActionType;
use App\Security\ApiKeyScopeGuard;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\User\UserServiceInterface;
use App\State\Moderation\Processor\CreateModerationActionProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;

#[AllowMockObjectsWithoutExpectations]
class CreateModerationActionProcessorTest extends TestCase
{
    private ModerationServiceInterface&MockObject $moderationService;
    private CommunityServiceInterface&MockObject $communityService;
    private ChannelServiceInterface&MockObject $channelService;
    private UserServiceInterface&MockObject $userService;
    private CreateModerationActionProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->moderationService = $this->createMock(ModerationServiceInterface::class);
        $this->communityService = $this->createMock(CommunityServiceInterface::class);
        $this->channelService = $this->createMock(ChannelServiceInterface::class);
        $this->userService = $this->createMock(UserServiceInterface::class);
        $this->processor = new CreateModerationActionProcessor(
            $this->moderationService,
            $this->communityService,
            $this->channelService,
            $this->userService,
            new ApiKeyScopeGuard(new RequestStack()),
        );
    }

    public function testWarnDispatchesToWarn(): void
    {
        $community = $this->createMock(Community::class);
        $target = $this->createMock(User::class);
        $action = $this->createMock(ModerationAction::class);

        $this->communityService->method('getByIdentifier')->willReturn($community);
        $this->userService->method('get')->willReturn($target);
        $this->moderationService->expects(self::once())
            ->method('warn')
            ->with($community, $target, 'reason')
            ->willReturn($action);

        $dto = new CreateModerationActionDto();
        $dto->targetUserId = 7;
        $dto->type = ModerationActionType::Warn;
        $dto->reason = 'reason';

        $result = $this->processor->process($dto, new Post(), ['community' => 'c1']);
        self::assertSame($action, $result);
    }

    public function testTimeoutResolvesChannelAndDispatches(): void
    {
        $community = $this->createMock(Community::class);
        $channel = $this->createMock(Channel::class);
        $target = $this->createMock(User::class);
        $action = $this->createMock(ModerationAction::class);
        $expires = new \DateTimeImmutable('+1 hour');

        $this->communityService->method('getByIdentifier')->willReturn($community);
        $this->userService->method('get')->willReturn($target);
        $this->channelService->method('getByIdentifier')->willReturn($channel);
        $this->moderationService->expects(self::once())
            ->method('timeout')
            ->with($community, $target, null, $expires, $channel)
            ->willReturn($action);

        $dto = new CreateModerationActionDto();
        $dto->targetUserId = 7;
        $dto->type = ModerationActionType::Timeout;
        $dto->expiresAt = $expires;
        $dto->channelIdentifier = 'ch1';

        $result = $this->processor->process($dto, new Post(), ['community' => 'c1']);
        self::assertSame($action, $result);
    }
}
