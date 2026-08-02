<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Enum\Admin\AdminAuditAction;
use App\Security\SecurityContext;
use App\Service\Admin\AdminAuditLogger;
use App\Service\Community\CommunityMembershipServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class AdminAuditLoggerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Security&MockObject $security;
    private AdminAuditLogger $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->logger = new AdminAuditLogger(new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->em);
    }

    public function testRecordsRowWithCurrentActor(): void
    {
        $actor = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($actor);

        $captured = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->with(self::callback(function ($entity) use (&$captured) {
                $captured = $entity;

                return $entity instanceof AdminAuditLog;
            }));
        $this->em->expects(self::once())->method('flush');

        $this->logger->record(
            AdminAuditAction::UserPromote,
            'user',
            42,
            ['note' => 'promoted by ops'],
        );

        self::assertInstanceOf(AdminAuditLog::class, $captured);
        self::assertSame(AdminAuditAction::UserPromote, $captured->getAction());
        self::assertSame('user', $captured->getTargetType());
        self::assertSame(42, $captured->getTargetId());
        self::assertSame(['note' => 'promoted by ops'], $captured->getPayload());
        self::assertSame($actor, $captured->getActor());
    }

    public function testRecordsWithNullActorWhenNoSecurityToken(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $captured = null;
        $this->em->expects(self::once())
            ->method('persist')
            ->with(self::callback(function ($entity) use (&$captured) {
                $captured = $entity;

                return $entity instanceof AdminAuditLog;
            }));

        $this->logger->record(AdminAuditAction::ServerConfigUpdate);

        self::assertInstanceOf(AdminAuditLog::class, $captured);
        self::assertNull($captured->getActor());
    }
}
