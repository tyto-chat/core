<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Repository\CommunityMemberRepository;
use App\Service\Community\CommunityMembershipService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CommunityMembershipServiceTest extends TestCase
{
    private CommunityMemberRepository&MockObject $repo;
    private CommunityMembershipService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->repo = $this->createMock(CommunityMemberRepository::class);
        $this->service = new CommunityMembershipService($this->repo);
    }

    public function testIsMemberDelegatesToRepository(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->createMock(Community::class);
        $this->repo->method('isMember')->willReturn(true);

        self::assertTrue($this->service->isMember($user, $community));
    }

    public function testFindRoleDelegatesToRepository(): void
    {
        $user = $this->createMock(User::class);
        $community = $this->createMock(Community::class);
        $this->repo->method('findRole')->willReturn(CommunityRole::Moderator);

        self::assertSame(CommunityRole::Moderator, $this->service->findRole($user, $community));
    }

    public function testFindMemberUserIdsDelegatesToRepository(): void
    {
        $community = $this->createMock(Community::class);
        $this->repo->method('findMemberUserIds')->willReturn([1, 2, 3]);

        self::assertSame([1, 2, 3], $this->service->findMemberUserIds($community));
    }

    public function testExistsSharedCommunityDelegatesToRepository(): void
    {
        $a = $this->createMock(User::class);
        $b = $this->createMock(User::class);
        $this->repo->method('existsSharedCommunity')->willReturn(true);

        self::assertTrue($this->service->existsSharedCommunity($a, $b));
    }

    public function testFindByIdDelegatesToRepository(): void
    {
        $community = $this->createMock(Community::class);
        $member = $this->createMock(CommunityMember::class);
        $this->repo->method('findOneBy')->willReturn($member);

        self::assertSame($member, $this->service->findById(7, $community));
    }
}
