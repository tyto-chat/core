<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Conversation;
use App\Entity\ConversationMember;
use App\Entity\User;
use App\Exception\Conversation\EmptyMemberListException;
use App\Exception\Conversation\NoSharedCommunityException;
use App\Exception\Conversation\NotMemberException;
use App\Repository\ConversationMemberRepository;
use App\Repository\ConversationRepository;
use App\Repository\MessagePageRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationService;
use App\Service\Message\MessageServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
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

        $this->service = new ConversationService(
            new SecurityContext($this->security, $this->communityMembershipService),
            $this->communityMembershipService,
            $this->conversationRepository,
            $this->memberRepository,
            $this->pageRepository,
            $this->messageService,
            $this->createMock(ManagerRegistry::class),
        );
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
}
