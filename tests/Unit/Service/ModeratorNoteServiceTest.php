<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Community;
use App\Entity\ModeratorNote;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Repository\ModeratorNoteRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Moderation\ModeratorNoteService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class ModeratorNoteServiceTest extends TestCase
{
    private ModeratorNoteRepository&MockObject $repo;
    private CommunityMembershipServiceInterface&MockObject $membership;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private ModeratorNoteService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->repo = $this->createMock(ModeratorNoteRepository::class);
        $this->membership = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new ModeratorNoteService(new SecurityContext($this->security, $this->membership), $this->repo);
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    private function authedUser(bool $admin = false): User&MockObject
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturnMap([
            ['ROLE_USER', null, true],
            ['ROLE_ADMIN', null, $admin],
        ]);

        return $user;
    }

    public function testNewDeniesNonGlobalMod(): void
    {
        $this->authedUser();
        $this->membership->method('findRole')->willReturn(CommunityRole::Member);

        $this->expectException(AccessDeniedException::class);
        $this->service->new($this->createMock(Community::class), $this->createMock(User::class), 'note');
    }

    public function testNewPersistsForModerator(): void
    {
        $this->authedUser();
        $this->membership->method('findRole')->willReturn(CommunityRole::Moderator);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $note = $this->service->new($this->createMock(Community::class), $this->createMock(User::class), 'note');
        self::assertSame('note', $note->getContent());
    }

    public function testUpdateDeniesNonAuthor(): void
    {
        $actor = $this->authedUser();
        $actor->method('getId')->willReturn(1);

        $author = $this->createMock(User::class);
        $author->method('getId')->willReturn(2);
        $note = $this->createMock(ModeratorNote::class);
        $note->method('getAuthor')->willReturn($author);

        $this->expectException(AccessDeniedException::class);
        $this->service->update($note, 'new content');
    }

    public function testCommunityAdminCanUpdateAnotherAuthorsNote(): void
    {
        $actor = $this->authedUser();
        $actor->method('getId')->willReturn(1);

        $author = $this->createMock(User::class);
        $author->method('getId')->willReturn(2);
        $community = $this->createMock(Community::class);
        $note = $this->createMock(ModeratorNote::class);
        $note->method('getAuthor')->willReturn($author);
        $note->method('getCommunity')->willReturn($community);

        // Actor is a community admin of the note's community (not the author).
        $this->membership->method('isAdmin')->willReturn(true);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $note->expects(self::once())->method('setContent')->with('corrected');
        $this->service->update($note, 'corrected');
    }
}
