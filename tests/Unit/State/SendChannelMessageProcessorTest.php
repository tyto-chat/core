<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Post;
use App\Dto\Message\SendMessageDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Message;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Message\MessageServiceInterface;
use App\State\Message\Processor\SendChannelMessageProcessor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SendChannelMessageProcessorTest extends TestCase
{
    private CommunityServiceInterface&MockObject $communityService;
    private ChannelServiceInterface&MockObject $channelService;
    private MessageServiceInterface&MockObject $messageService;
    private SendChannelMessageProcessor $processor;

    #[\Override]
    protected function setUp(): void
    {
        $this->communityService = $this->createMock(CommunityServiceInterface::class);
        $this->channelService = $this->createMock(ChannelServiceInterface::class);
        $this->messageService = $this->createMock(MessageServiceInterface::class);
        $this->processor = new SendChannelMessageProcessor(
            $this->communityService,
            $this->channelService,
            $this->messageService,
        );
    }

    public function testProcessDelegatesWithAttachments(): void
    {
        $community = $this->createMock(Community::class);
        $channel = $this->createMock(Channel::class);
        $message = $this->createMock(Message::class);

        $this->communityService->method('getByIdentifier')->willReturn($community);
        $this->channelService->method('getByIdentifier')->willReturn($channel);
        $this->messageService->expects(self::once())
            ->method('sendToChannel')
            ->with($channel, 'hi', ['/api/v1/media_objects/1'])
            ->willReturn($message);

        $dto = new SendMessageDto('hi', ['/api/v1/media_objects/1']);

        $result = $this->processor->process($dto, new Post(), ['community' => 'c1', 'channel' => 'ch1']);

        self::assertSame($message, $result);
    }
}
