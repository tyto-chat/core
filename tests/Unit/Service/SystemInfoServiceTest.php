<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\ServerInfo\SystemInfoService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class SystemInfoServiceTest extends TestCase
{
    private CommunityServiceInterface&MockObject $communityService;
    private Security&MockObject $security;
    private SystemInfoService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->communityService = $this->createMock(CommunityServiceInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new SystemInfoService(
            new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)),
            $this->communityService,
            $this->createMock(MediaObjectServiceInterface::class),
        );
    }

    public function testGetSystemInfoDeniesNonAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getSystemInfo();
    }

    public function testGetCommunityStatsDeniesNonAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->getCommunityStats();
    }

    public function testGetCommunityStatsMapsRowsForAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->communityService->method('findAllWithStats')->willReturn([[
            'id' => 1,
            'identifier' => 'c1',
            'name' => 'Community One',
            'channelCount' => 2,
            'memberCount' => 3,
            'messageCount' => 4,
            'attachmentCount' => 5,
            'attachmentsSize' => 6,
        ]]);

        $result = $this->service->getCommunityStats();

        self::assertCount(1, $result);
        self::assertSame('c1', $result[0]->identifier);
        self::assertSame(2, $result[0]->channelCount);
    }
}
