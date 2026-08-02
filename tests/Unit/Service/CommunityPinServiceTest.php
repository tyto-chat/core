<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Community;
use App\Entity\CommunityPin;
use App\Entity\User;
use App\Exception\Community\InvalidPinReorderException;
use App\Repository\CommunityPinRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityPinService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class CommunityPinServiceTest extends TestCase
{
    private CommunityPinRepository&MockObject $pinRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private CommunityPinService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->pinRepository = $this->createMock(CommunityPinRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new CommunityPinService(new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->pinRepository);
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    private function community(int $id): Community
    {
        $community = $this->createMock(Community::class);
        $community->method('getId')->willReturn($id);

        return $community;
    }

    public function testPinForIsIdempotentWhenAlreadyPinned(): void
    {
        $user = $this->createMock(User::class);
        $existing = $this->createMock(CommunityPin::class);

        $this->pinRepository->method('findOneByUserAndCommunity')->willReturn($existing);
        $this->entityManager->expects(self::never())->method('persist');

        self::assertSame($existing, $this->service->pinFor($user, $this->community(1)));
    }

    public function testPinForAppendsAtPositionZeroForFirstPin(): void
    {
        $user = $this->createMock(User::class);

        $this->pinRepository->method('findOneByUserAndCommunity')->willReturn(null);
        $this->pinRepository->method('findMaxPosition')->willReturn(null);
        $this->entityManager->expects(self::once())->method('persist')->with(self::callback(
            static fn (CommunityPin $p): bool => 0 === $p->getPosition()
        ));
        $this->entityManager->expects(self::once())->method('flush');

        $pin = $this->service->pinFor($user, $this->community(1));
        self::assertSame(0, $pin->getPosition());
    }

    public function testPinForAppendsAfterHighestPosition(): void
    {
        $user = $this->createMock(User::class);
        $this->pinRepository->method('findOneByUserAndCommunity')->willReturn(null);
        $this->pinRepository->method('findMaxPosition')->willReturn(4);

        $pin = $this->service->pinFor($user, $this->community(1));
        self::assertSame(5, $pin->getPosition());
    }

    public function testReorderRequiresAllExistingPins(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $this->pinRepository->method('findByUser')->willReturn([
            $this->pin(1, 0),
            $this->pin(2, 1),
        ]);

        $this->expectException(InvalidPinReorderException::class);
        $this->service->reorder([1]); // missing community 2
    }

    public function testReorderRejectsUnknownCommunityId(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $this->pinRepository->method('findByUser')->willReturn([
            $this->pin(1, 0),
            $this->pin(2, 1),
        ]);

        $this->expectException(InvalidPinReorderException::class);
        $this->service->reorder([1, 99]); // 99 isn't pinned
    }

    public function testReorderRejectsDuplicates(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $this->pinRepository->method('findByUser')->willReturn([
            $this->pin(1, 0),
            $this->pin(2, 1),
        ]);

        $this->expectException(InvalidPinReorderException::class);
        $this->service->reorder([1, 1]);
    }

    public function testReorderUpdatesPositionsByOrder(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $pinA = $this->pin(1, 0);
        $pinB = $this->pin(2, 1);
        $pinC = $this->pin(3, 2);
        $this->pinRepository->method('findByUser')->willReturn([$pinA, $pinB, $pinC]);

        $this->entityManager->expects(self::once())->method('flush');

        $this->service->reorder([3, 1, 2]); // new order: C, A, B

        self::assertSame(0, $pinC->getPosition());
        self::assertSame(1, $pinA->getPosition());
        self::assertSame(2, $pinB->getPosition());
    }

    public function testUnpinIsNoOpWhenPinMissing(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $this->pinRepository->method('findOneByUserAndCommunity')->willReturn(null);
        $this->entityManager->expects(self::never())->method('remove');

        $this->service->unpin($this->community(1));
    }

    public function testUnpinRemovesPinWhenPresent(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->security->method('getUser')->willReturn($this->createMock(User::class));

        $pin = $this->pin(1, 0);
        $this->pinRepository->method('findOneByUserAndCommunity')->willReturn($pin);
        $this->entityManager->expects(self::once())->method('remove')->with($pin);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->unpin($this->community(1));
    }

    private function pin(int $communityId, int $position): CommunityPin
    {
        $pin = new CommunityPin();
        $pin->setCommunity($this->community($communityId));
        $pin->setPosition($position);

        return $pin;
    }
}
