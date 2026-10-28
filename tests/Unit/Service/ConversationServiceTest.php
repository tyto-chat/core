<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Conversation\PolicyDecision;
use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\User;
use App\Enum\Conversation\ConversationWrite;
use App\Enum\Conversation\PolicyOutcome;
use App\Enum\Conversation\ReadOnlyReason;
use App\Exception\Conversation\ConversationReadOnlyException;
use App\Exception\Conversation\EmptyMemberListException;
use App\Exception\Conversation\NoSharedCommunityException;
use App\Exception\Conversation\NotMemberException;
use App\Repository\ConversationMemberRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessagePageRepository;
use App\Security\ConversationPolicyInterface;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationService;
use App\Service\Message\MessageServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\User\UserBlockServiceInterface;
use App\ValueObject\User\BlockRelations;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class ConversationServiceTest extends TestCase
{
    private ConversationRepository&MockObject $conversationRepository;
    private ConversationMemberRepository&MockObject $memberRepository;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private MessagePageRepository&MockObject $pageRepository;
    private MessageServiceInterface&MockObject $messageService;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private NotificationServiceInterface&MockObject $notifications;
    private UserBlockServiceInterface&MockObject $blocks;
    private ConversationPolicyInterface&Stub $policy;
    private \App\Service\E2ee\E2eeServiceInterface&Stub $e2ee;
    private \App\Service\User\UserPreferenceServiceInterface&Stub $preferences;
    /** @var array<int, mixed> */
    private array $constructorArgs;
    private ConversationService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->conversationRepository = $this->createMock(ConversationRepository::class);
        $this->memberRepository = $this->createMock(ConversationMemberRepository::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->pageRepository = $this->createMock(MessagePageRepository::class);
        $this->messageService = $this->createMock(MessageServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->notifications = $this->createMock(NotificationServiceInterface::class);

        $this->blocks = $this->createMock(UserBlockServiceInterface::class);
        $this->policy = self::createStub(ConversationPolicyInterface::class);
        $this->policy->method('decide')->willReturn(new PolicyDecision(PolicyOutcome::PlainFallback, false, null, [], []));

        $this->e2ee = self::createStub(\App\Service\E2ee\E2eeServiceInterface::class);
        $this->e2ee->method('mode')->willReturn(\App\Enum\Settings\EncryptedDmsMode::Enabled);
        $this->preferences = self::createStub(\App\Service\User\UserPreferenceServiceInterface::class);
        $this->preferences->method('refusingPlainDms')->willReturn([]);

        $this->constructorArgs = [
            new SecurityContext($this->security, $this->communityMembershipService),
            $this->communityMembershipService,
            $this->conversationRepository,
            $this->memberRepository,
            $this->pageRepository,
            $this->messageService,
            $this->createMock(ManagerRegistry::class),
            $this->notifications,
            $this->blocks,
            $this->createMock(\App\Service\Realtime\RealtimePublisherInterface::class),
            $this->policy,
            $this->e2ee,
            new \App\Security\ApiKeyScopeGuard(new \Symfony\Component\HttpFoundation\RequestStack()),
            $this->preferences,
        ];
        $this->service = new ConversationService(...$this->constructorArgs);
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    private function user(int $id): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);

        return $user;
    }

    private function authenticate(User $caller, bool $isAdmin = false): void
    {
        $this->security->method('getUser')->willReturn($caller);
        $this->security->method('isGranted')->willReturnMap([
            ['ROLE_USER', null, true],
            ['ROLE_ADMIN', null, $isAdmin],
        ]);
    }

    public function testCreateOrFindRejectsEmptyList(): void
    {
        $this->authenticate($this->user(1));

        $this->expectException(EmptyMemberListException::class);
        $this->service->createOrFind([]);
    }

    public function testCreateOrFindRejectsParticipantWithoutSharedCommunity(): void
    {
        $caller = $this->user(1);
        $stranger = $this->user(2);
        $this->authenticate($caller);
        $this->communityMembershipService->method('existsSharedCommunity')->willReturn(false);

        $this->expectException(NoSharedCommunityException::class);
        $this->service->createOrFind([$stranger]);
    }

    public function testCreateOrFindReturnsExistingConversationForSameParticipants(): void
    {
        $caller = $this->user(1);
        $other = $this->user(2);
        $existing = $this->createMock(Conversation::class);

        $this->authenticate($caller);
        $this->communityMembershipService->method('existsSharedCommunity')->willReturn(true);
        $this->conversationRepository
            ->method('findOneByParticipantsHash')
            ->willReturn($existing);

        $this->entityManager->expects(self::never())->method('persist');
        $result = $this->service->createOrFind([$other]);

        self::assertSame($existing, $result);
    }

    public function testCreateOrFindPersistsWhenNoExistingMatch(): void
    {
        $caller = $this->user(1);
        $other = $this->user(2);

        $this->authenticate($caller);
        $this->communityMembershipService->method('existsSharedCommunity')->willReturn(true);
        $this->conversationRepository->method('findOneByParticipantsHash')->willReturn(null);
        $this->blocks->method('enforcedRelations')->willReturn(new BlockRelations());

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->createOrFind([$other]);

        self::assertSame(Conversation::hashParticipants([1, 2]), $result->getParticipantsHash());
        self::assertCount(2, $result->getMembers());
    }

    public function testSetMutedStoresTimestamp(): void
    {
        $caller = $this->user(1);
        $conversation = $this->createMock(Conversation::class);
        $member = new ConversationMember();
        $this->authenticate($caller);
        $this->memberRepository->method('findForUser')->willReturn($member);

        $when = new \DateTimeImmutable('+1 hour');
        $this->service->setMuted($conversation, $when);

        self::assertSame($when, $member->getMutedUntil());
    }

    public function testSetMutedThrowsWhenNotMember(): void
    {
        $caller = $this->user(1);
        $conversation = $this->createMock(Conversation::class);
        $this->authenticate($caller);
        $this->memberRepository->method('findForUser')->willReturn(null);

        $this->expectException(NotMemberException::class);
        $this->service->setMuted($conversation, null);
    }

    public function testMarkReadBumpsLastReadAt(): void
    {
        $caller = $this->user(1);
        $conversation = $this->createMock(Conversation::class);
        $member = new ConversationMember();
        $this->authenticate($caller);
        $this->memberRepository->method('findForUser')->willReturn($member);

        $this->notifications->expects(self::once())->method('markConversationAsRead')->with($conversation);

        $before = new \DateTimeImmutable();
        $this->service->markRead($conversation);

        self::assertNotNull($member->getLastReadAt());
        self::assertGreaterThanOrEqual($before, $member->getLastReadAt());
    }

    public function testMarkReadThrowsWhenNotMember(): void
    {
        $caller = $this->user(1);
        $conversation = $this->createMock(Conversation::class);
        $this->authenticate($caller);
        $this->memberRepository->method('findForUser')->willReturn(null);

        $this->expectException(NotMemberException::class);
        $this->service->markRead($conversation);
    }

    private function conversationWith(User ...$users): Conversation
    {
        $conversation = new Conversation();
        foreach ($users as $user) {
            $conversation->addMember((new ConversationMember())->setUser($user));
        }

        return $conversation;
    }

    public function testReadOnlyReasonDistinguishesBlockerFromBlocked(): void
    {
        $viewer = $this->user(1);
        $conversation = $this->conversationWith($viewer, $this->user(2));

        $this->blocks->method('enforcedRelations')->willReturnOnConsecutiveCalls(
            new BlockRelations(blockedIds: [2], blockerIds: [2]),
            new BlockRelations(blockerIds: [2]),
            new BlockRelations(),
        );

        self::assertSame(ReadOnlyReason::Blocked, $this->service->readOnlyReason($conversation, $viewer));
        self::assertSame(ReadOnlyReason::Unavailable, $this->service->readOnlyReason($conversation, $viewer));
        self::assertNull($this->service->readOnlyReason($conversation, $viewer));
    }

    public function testReadOnlyReasonIgnoresGroupConversations(): void
    {
        $viewer = $this->user(1);
        $this->blocks->expects(self::never())->method('enforcedRelations');

        self::assertNull($this->service->readOnlyReason($this->conversationWith($viewer, $this->user(2), $this->user(3)), $viewer));
    }

    public function testAssertWritableAllowsUnreactAndDeleteWithoutLookup(): void
    {
        $caller = $this->user(1);
        $this->authenticate($caller);
        $this->blocks->expects(self::never())->method('enforcedRelations');
        $conversation = $this->conversationWith($caller, $this->user(2));

        foreach ([ConversationWrite::Unreact, ConversationWrite::DeleteMessage, ConversationWrite::DeleteAttachment, ConversationWrite::Mute, ConversationWrite::MarkRead] as $write) {
            $this->service->assertWritable($conversation, $write);
        }
    }

    public function testDeleteAttachmentMuteAndMarkReadCallAssertWritable(): void
    {
        $caller = $this->user(1);
        $this->authenticate($caller);
        $conversation = $this->conversationWith($caller, $this->user(2));
        $this->memberRepository->method('findForUser')->willReturn(new ConversationMember());
        $service = $this->getMockBuilder(ConversationService::class)
            ->setConstructorArgs($this->constructorArgs)
            ->onlyMethods(['assertWritable'])
            ->getMock();
        $service->setEntityManager($this->entityManager);
        $service->setLogger(new NullLogger());
        $calls = [];
        $service->method('assertWritable')->willReturnCallback(static function (Conversation $c, ConversationWrite $write) use (&$calls): void {
            $calls[] = $write;
        });

        $service->setMuted($conversation, null);
        $service->markRead($conversation);

        self::assertSame([ConversationWrite::Mute, ConversationWrite::MarkRead], $calls);
    }

    public function testReadOnlyReasonOperatorRequiredForPlainGroups(): void
    {
        $viewer = $this->user(1);
        $e2ee = self::createStub(\App\Service\E2ee\E2eeServiceInterface::class);
        $e2ee->method('mode')->willReturn(\App\Enum\Settings\EncryptedDmsMode::Required);
        $this->constructorArgs[11] = $e2ee;
        $service = new ConversationService(...$this->constructorArgs);
        $this->blocks->method('enforcedRelations')->willReturn(new BlockRelations());

        self::assertSame(ReadOnlyReason::OperatorRequired, $service->readOnlyReason($this->conversationWith($viewer, $this->user(2), $this->user(3)), $viewer));
        self::assertNull($service->readOnlyReason($this->encryptedConversationWith($viewer, $this->user(2)), $viewer));
    }

    public function testReadOnlyReasonParticipantRefusalWinsOverBlock(): void
    {
        $viewer = $this->user(1);
        $preferences = self::createStub(\App\Service\User\UserPreferenceServiceInterface::class);
        $preferences->method('refusingPlainDms')->willReturn([2]);
        $this->constructorArgs[13] = $preferences;
        $service = new ConversationService(...$this->constructorArgs);
        $this->blocks->method('enforcedRelations')->willReturn(new BlockRelations(blockedIds: [2]));

        self::assertSame(ReadOnlyReason::ParticipantRefusesPlain, $service->readOnlyReason($this->conversationWith($viewer, $this->user(2)), $viewer));
        self::assertSame(ReadOnlyReason::Blocked, $service->readOnlyReason($this->encryptedConversationWith($viewer, $this->user(2)), $viewer));
    }

    private function encryptedConversationWith(User ...$users): Conversation
    {
        $conversation = new Conversation(true);
        foreach ($users as $user) {
            $conversation->addMember((new ConversationMember())->setUser($user));
        }

        return $conversation;
    }

    public function testAssertWritableRejectsSendWhenReadOnly(): void
    {
        $caller = $this->user(1);
        $this->authenticate($caller);
        $this->blocks->method('enforcedRelations')->willReturn(new BlockRelations(blockerIds: [2]));

        $this->expectException(ConversationReadOnlyException::class);
        $this->service->assertWritable($this->conversationWith($caller, $this->user(2)), ConversationWrite::Send);
    }
}
