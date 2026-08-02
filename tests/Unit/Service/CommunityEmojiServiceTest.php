<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Community;
use App\Entity\CommunityEmoji;
use App\Entity\MediaObject;
use App\Exception\CommunityEmoji\ShortcodeAlreadyTakenException;
use App\Repository\CommunityEmojiRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityEmojiService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Reaction\ReactionServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class CommunityEmojiServiceTest extends TestCase
{
    private CommunityEmojiRepository&MockObject $repo;
    private ReactionServiceInterface&MockObject $reactionService;
    private RealtimePublisherInterface&MockObject $publisher;
    private CachePurgerInterface&MockObject $cachePurger;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private CommunityEmojiService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->repo = $this->createMock(CommunityEmojiRepository::class);
        $this->reactionService = $this->createMock(ReactionServiceInterface::class);
        $this->publisher = $this->createMock(RealtimePublisherInterface::class);
        $this->cachePurger = $this->createMock(CachePurgerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new CommunityEmojiService(
            new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)),
            $this->repo,
            $this->reactionService,
            $this->createMock(MediaObjectServiceInterface::class),
            $this->publisher,
            $this->cachePurger,
        );
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    public function testGetAllForCommunityDeniesWithoutView(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getAllForCommunity($this->createMock(Community::class));
    }

    public function testNewCustomDeniesWithoutManage(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->newCustom(
            $this->createMock(Community::class),
            ':smile:',
            $this->createMock(MediaObject::class),
        );
    }

    public function testNewCustomRejectsDuplicateShortcode(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->repo->method('findByCommunityAndShortcode')->willReturn($this->createMock(CommunityEmoji::class));

        $this->expectException(ShortcodeAlreadyTakenException::class);
        $this->service->newCustom(
            $this->createMock(Community::class),
            ':smile:',
            $this->createMock(MediaObject::class),
        );
    }

    public function testNewCustomPersistsAndPublishes(): void
    {
        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('acme');

        $this->security->method('isGranted')->willReturn(true);
        $this->repo->method('findByCommunityAndShortcode')->willReturn(null);
        $this->repo->method('findMaxPosition')->willReturn(null);

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');
        $this->publisher->expects(self::once())->method('publishCommunityEmojisUpdated');
        $this->cachePurger->expects(self::once())->method('purgeCommunityEmojis')->with('acme');

        $emoji = $this->service->newCustom(
            $community,
            ':smile:',
            $this->createMock(MediaObject::class),
            'smile',
        );

        self::assertSame(':smile:', $emoji->getShortcode());
    }

    public function testDeletePurgesEmojisExactlyOnce(): void
    {
        $community = $this->createMock(Community::class);
        $community->method('getIdentifier')->willReturn('acme');

        $emoji = $this->createMock(CommunityEmoji::class);
        $emoji->method('getCommunity')->willReturn($community);
        $emoji->method('getShortcode')->willReturn(':smile:');
        $emoji->method('getImage')->willReturn(null);

        $this->security->method('isGranted')->willReturn(true);
        $this->entityManager->expects(self::once())->method('remove');
        $this->entityManager->expects(self::once())->method('flush');
        $this->publisher->expects(self::once())->method('publishCommunityEmojisUpdated');
        $this->cachePurger->expects(self::once())->method('purgeCommunityEmojis')->with('acme');

        $this->service->delete($emoji);
    }
}
