<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\Profile\UpdateProfileDto;
use App\Entity\Profile;
use App\Exception\Profile\ProfileNotFoundException;
use App\Repository\ProfileRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\User\ProfileService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class ProfileServiceTest extends TestCase
{
    private ProfileRepository&MockObject $profileRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private ProfileService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->profileRepository = $this->createMock(ProfileRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $this->service = new ProfileService(new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)), $this->profileRepository);
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->profileRepository->method('findOneBy')->willReturn(null);

        $this->expectException(ProfileNotFoundException::class);
        $this->service->get(999);
    }

    public function testGetThrowsAccessDeniedWhenNotGranted(): void
    {
        $profile = $this->createMock(Profile::class);
        $this->profileRepository->method('findOneBy')->willReturn($profile);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->get(1);
    }

    public function testGetReturnsProfileWhenGranted(): void
    {
        $profile = $this->createMock(Profile::class);
        $this->profileRepository->method('findOneBy')->willReturn($profile);
        $this->security->method('isGranted')->willReturn(true);

        self::assertSame($profile, $this->service->get(1));
    }

    public function testUpdateThrowsAccessDeniedWhenNotGranted(): void
    {
        $profile = $this->createMock(Profile::class);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->update($profile, new UpdateProfileDto());
    }

    public function testUpdateSavesAndReturnsProfile(): void
    {
        $profile = new Profile();
        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::once())->method('persist')->with($profile);
        $this->entityManager->expects(self::once())->method('flush');

        $result = $this->service->update($profile, new UpdateProfileDto());

        self::assertSame($profile, $result);
    }
}
