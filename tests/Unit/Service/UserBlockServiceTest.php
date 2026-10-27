<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Entity\UserBlock;
use App\Repository\UserBlockRepository;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use App\Service\User\UserBlockService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class UserBlockServiceTest extends TestCase
{
    public function testConcurrentDoubleBlockReturnsTheRowWonByTheOtherRequest(): void
    {
        $me = $this->createMock(User::class);
        $me->method('getId')->willReturn(1);
        $target = $this->createMock(User::class);
        $target->method('getId')->willReturn(2);
        $target->method('isAdmin')->willReturn(false);

        $winner = (new UserBlock())->setBlocker($me)->setBlocked($target);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($me);
        $security->method('isGranted')->willReturn(true);
        $memberships = $this->createMock(CommunityMembershipServiceInterface::class);
        $memberships->method('findStaffSharedPairs')->willReturn([]);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($target);
        $blocks = $this->createMock(UserBlockRepository::class);
        $blocks->expects(self::exactly(2))->method('findOneByPair')->willReturnOnConsecutiveCalls(null, $winner);
        $blocks->method('countByBlocker')->willReturn(0);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException(new UniqueConstraintViolationException($this->createMock(DriverException::class), null));

        $service = new UserBlockService(
            new SecurityContext($security, $memberships),
            $blocks,
            $users,
            $memberships,
            $this->createMock(StructureRealtimePublisherInterface::class),
            $registry,
            $this->createMock(ConversationServiceInterface::class),
        );
        $service->setEntityManager($entityManager);
        $service->setLogger(new NullLogger());

        self::assertSame($winner, $service->block(2));
    }
}
