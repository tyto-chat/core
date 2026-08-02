<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\CommunityEmoji;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Reaction;
use App\Entity\User;
use App\Exception\Reaction\EmojiNotAllowedException;
use App\Repository\ReactionRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Reaction\ReactionService;
use App\Service\Realtime\RealtimePublisherInterface;
use App\Service\Webhook\WebhookEmitterInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class ReactionServiceTest extends TestCase
{
    private ReactionRepository&MockObject $reactionRepository;
    private RealtimePublisherInterface&MockObject $publisher;
    private CommunityEmojiServiceInterface&MockObject $communityEmojiService;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private ReactionService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->reactionRepository = $this->createMock(ReactionRepository::class);
        $this->publisher = $this->createMock(RealtimePublisherInterface::class);
        $this->communityEmojiService = $this->createMock(CommunityEmojiServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new ReactionService(
            new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)),
            $this->reactionRepository,
            $this->publisher,
            $this->communityEmojiService,
            $this->createMock(WebhookEmitterInterface::class),
        );
        $this->service->setEntityManager($this->entityManager);
    }

    private function messageInChannel(?Channel $channel): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        return $message;
    }

    private function channelInCommunity(?Community $community = null): Channel
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getCommunity')->willReturn($community ?? $this->createMock(Community::class));

        return $channel;
    }

    private function messageInConversation(): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn(null);
        $message->method('getConversation')->willReturn($this->createMock(Conversation::class));

        return $message;
    }

    public function testNewThrowsWhenChannelAccessDenied(): void
    {
        $channel = $this->createMock(Channel::class);
        $message = $this->messageInChannel($channel);
        $emoji = '👍';

        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->new($message, $emoji);
    }

    public function testNewReturnsExistingReactionWithoutDuplicating(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->channelInCommunity();
        $message = $this->messageInChannel($channel);
        $existing = $this->createMock(Reaction::class);
        $emoji = '👍';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->reactionRepository->method('findByMessageAndUser')->willReturn($existing);

        $this->entityManager->expects(self::never())->method('persist');

        $result = $this->service->new($message, $emoji);

        self::assertSame($existing, $result);
    }

    public function testNewCreatesReactionAndPublishes(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->channelInCommunity();
        $message = $this->messageInChannel($channel);
        $emoji = '👍';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->reactionRepository->method('findByMessageAndUser')->willReturn(null);
        $this->reactionRepository->method('buildCache')->willReturn([]);

        $this->entityManager->expects(self::atLeastOnce())->method('persist');
        $this->entityManager->expects(self::atLeastOnce())->method('flush');
        $this->publisher->expects(self::once())->method('publishMessageReactions')->with($message);

        $result = $this->service->new($message, $emoji);

        self::assertSame('👍', $result->getEmoji());
    }

    public function testNewAcceptsAnyUnicodeGlyphWithoutCommunityLookup(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->channelInCommunity();
        $message = $this->messageInChannel($channel);
        $emoji = '🦄';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->communityEmojiService->expects(self::never())->method('findByShortcode');
        $this->reactionRepository->method('findByMessageAndUser')->willReturn(null);
        $this->reactionRepository->method('buildCache')->willReturn([]);

        $result = $this->service->new($message, $emoji);

        self::assertSame('🦄', $result->getEmoji());
    }

    public function testNewRejectsPlainTextNotAnEmoji(): void
    {
        $user = $this->createMock(User::class);
        $channel = $this->channelInCommunity();
        $message = $this->messageInChannel($channel);
        $emoji = 'lol';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);

        $this->expectException(EmojiNotAllowedException::class);
        $this->service->new($message, $emoji);
    }

    public function testNewLooksUpShortcodeWhenColonsPresent(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->createMock(Community::class);
        $channel = $this->channelInCommunity($community);
        $message = $this->messageInChannel($channel);
        $emoji = ':partyparrot:';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->communityEmojiService->expects(self::once())
            ->method('findByShortcode')
            ->with($community, ':partyparrot:')
            ->willReturn($this->createMock(CommunityEmoji::class));
        $this->reactionRepository->method('findByMessageAndUser')->willReturn(null);
        $this->reactionRepository->method('buildCache')->willReturn([]);

        $this->service->new($message, $emoji);
    }

    public function testNewAcceptsUnicodeReactionOnConversation(): void
    {
        $user = $this->createMock(User::class);
        $message = $this->messageInConversation();
        $emoji = '🎉';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);
        $this->communityEmojiService->expects(self::never())->method('findByShortcode');
        $this->reactionRepository->method('findByMessageAndUser')->willReturn(null);
        $this->reactionRepository->method('buildCache')->willReturn([]);

        $result = $this->service->new($message, $emoji);

        self::assertSame('🎉', $result->getEmoji());
    }

    public function testNewRejectsShortcodeReactionOnConversation(): void
    {
        $user = $this->createMock(User::class);
        $message = $this->messageInConversation();
        $emoji = ':partyparrot:';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);

        $this->expectException(EmojiNotAllowedException::class);
        $this->service->new($message, $emoji);
    }

    public function testNewRejectsPlainTextOnConversation(): void
    {
        $user = $this->createMock(User::class);
        $message = $this->messageInConversation();
        $emoji = 'hello';

        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($user);

        $this->expectException(EmojiNotAllowedException::class);
        $this->service->new($message, $emoji);
    }

    public function testDeleteThrowsWhenNotGranted(): void
    {
        $reaction = $this->createMock(Reaction::class);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->delete($reaction);
    }

    public function testDeleteRemovesReactionAndPublishes(): void
    {
        $message = $this->createMock(Message::class);
        $reaction = $this->createMock(Reaction::class);
        $reaction->method('getMessage')->willReturn($message);

        $this->security->method('isGranted')->willReturn(true);
        $this->reactionRepository->method('buildCache')->willReturn([]);

        $this->entityManager->expects(self::once())->method('remove')->with($reaction);
        $this->entityManager->expects(self::atLeastOnce())->method('flush');
        $this->publisher->expects(self::once())->method('publishMessageReactions')->with($message);

        $this->service->delete($reaction);
    }

    public function testDeleteSkipsPublishWhenMessageIsNull(): void
    {
        $reaction = $this->createMock(Reaction::class);
        $reaction->method('getMessage')->willReturn(null);

        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::once())->method('remove');
        $this->publisher->expects(self::never())->method('publishMessageReactions');

        $this->service->delete($reaction);
    }
}
