<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\User\ChangePasswordDto;
use App\Dto\User\CreateUserDto;
use App\Entity\MediaObject;
use App\Entity\Profile;
use App\Entity\User;
use App\Exception\User\InvalidCurrentPasswordException;
use App\Exception\User\UserNotFoundException;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Security\SessionRevokerInterface;
use App\Service\User\UserService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AllowMockObjectsWithoutExpectations]
class UserServiceTest extends TestCase
{
    private MediaObjectServiceInterface&MockObject $mediaObjectService;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private UserRepository&MockObject $userRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private UserService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->mediaObjectService = $this->createMock(MediaObjectServiceInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);

        $membership = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->service = new UserService(
            new SecurityContext($this->security, $this->createMock(CommunityMembershipServiceInterface::class)),
            $this->mediaObjectService,
            $this->passwordHasher,
            $this->userRepository,
            $membership,
            $this->createMock(\App\Service\Challenge\ChallengeServiceInterface::class),
            $this->createMock(\App\Service\Settings\SettingsServiceInterface::class),
            $this->createMock(\App\Service\IpReputation\IpReputationServiceInterface::class),
            $this->createMock(RequestStack::class),
            $this->createMock(SessionRevokerInterface::class),
            $this->createMock(\App\Service\Conversation\ConversationServiceInterface::class),
            $this->createMock(\App\Service\Notification\NotificationServiceInterface::class),
        );
        $this->service->setEntityManager($this->entityManager);
    }

    public function testGetReturnsUserWhenFound(): void
    {
        $user = new User();
        $this->security->method('isGranted')->willReturn(true);
        $this->userRepository->expects(self::once())->method('find')->with(42)->willReturn($user);

        self::assertSame($user, $this->service->get(42));
    }

    public function testGetThrowsUserNotFoundExceptionWhenMissing(): void
    {
        $this->security->method('isGranted')->willReturn(true);
        $this->userRepository->method('find')->willReturn(null);

        $this->expectException(UserNotFoundException::class);
        $this->service->get(99);
    }

    public function testNewHashesPasswordAndPersistsUser(): void
    {
        $this->passwordHasher
            ->method('hashPassword')
            ->willReturn('hashed_password');

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(User::class));
        $this->entityManager->expects(self::once())->method('flush');

        $dto = new CreateUserDto('user@example.com', 'plaintextpass', 'Test User');
        $user = $this->service->new($dto);

        self::assertSame('hashed_password', $user->getPassword());
    }

    public function testNewSetsEmailFromDto(): void
    {
        $this->passwordHasher->method('hashPassword')->willReturn('hash');
        $this->entityManager->method('persist');

        $user = $this->service->new(new CreateUserDto('alice@example.com', 'plaintextpass', 'Alice'));

        self::assertSame('alice@example.com', $user->getEmail());
    }

    public function testGetThrowsAccessDeniedWhenNotAuthenticated(): void
    {
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->service->get(1);
    }

    public function testFindByIdsDelegatesToRepository(): void
    {
        $users = [new User(), new User()];
        $this->userRepository->expects(self::once())->method('findBy')->with(['id' => [1, 2]])->willReturn($users);

        self::assertSame($users, $this->service->findByIds([1, 2]));
    }

    public function testSetAvatarPreparesAndLinksToProfile(): void
    {
        $avatar = new MediaObject();
        $profile = $this->createMock(Profile::class);
        $user = $this->createMock(User::class);
        $user->method('getProfile')->willReturn($profile);
        $profile->method('getAvatar')->willReturn(null);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);
        $this->mediaObjectService->expects(self::once())->method('prepare')->with($avatar, 'avatar');
        $this->mediaObjectService->expects(self::never())->method('delete');
        $profile->expects(self::once())->method('setAvatar')->with($avatar);

        $this->entityManager->method('persist');
        $this->entityManager->method('flush');

        $this->service->setAvatar($avatar);
    }

    public function testSetAvatarRepointsProfileBeforeDeletingOldAvatar(): void
    {
        $oldAvatar = new MediaObject();
        $newAvatar = new MediaObject();
        $profile = $this->createMock(Profile::class);
        $user = $this->createMock(User::class);
        $user->method('getProfile')->willReturn($profile);
        $profile->method('getAvatar')->willReturn($oldAvatar);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);

        $calls = [];
        $this->mediaObjectService->expects(self::once())->method('prepare')->with($newAvatar, 'avatar');
        $profile->expects(self::once())->method('setAvatar')->with($newAvatar)
            ->willReturnCallback(function () use (&$calls, $profile) {
                $calls[] = 'setAvatar';

                return $profile;
            });
        $this->entityManager->method('persist');
        $this->entityManager->method('flush')
            ->willReturnCallback(function () use (&$calls): void { $calls[] = 'flush'; });
        $this->mediaObjectService->expects(self::once())->method('delete')->with($oldAvatar)
            ->willReturnCallback(function () use (&$calls): void { $calls[] = 'delete'; });

        $this->service->setAvatar($newAvatar);

        self::assertSame('delete', end($calls), 'old avatar must be deleted after the profile is repointed and flushed');
        self::assertLessThan(
            array_search('delete', $calls, true),
            array_search('flush', $calls, true),
            'profile.avatar_id must be flushed to the new avatar before deleting the old one (FK constraint)',
        );
    }

    public function testChangePasswordThrowsWhenCurrentPasswordIsInvalid(): void
    {
        $user = new User();
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);
        $this->passwordHasher->method('isPasswordValid')->willReturn(false);

        $dto = new ChangePasswordDto(currentPassword: 'wrongpassword', newPassword: 'newpassword1');

        $this->expectException(InvalidCurrentPasswordException::class);
        $this->service->changePassword($dto);
    }

    public function testChangePasswordHashesAndSavesNewPassword(): void
    {
        $user = new User();
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);
        $this->passwordHasher->method('isPasswordValid')->willReturn(true);
        $this->passwordHasher->method('hashPassword')->willReturn('new_hash');

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $dto = new ChangePasswordDto(currentPassword: 'correctpassword', newPassword: 'newpassword1');

        $this->service->changePassword($dto);

        self::assertSame('new_hash', $user->getPassword());
    }
}
