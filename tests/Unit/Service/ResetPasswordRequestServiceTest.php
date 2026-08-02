<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\User\PasswordResetDto;
use App\Dto\User\RequestPasswordResetDto;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Service\Security\SessionRevokerInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\ResetPasswordRequestService;
use App\Service\User\UserServiceInterface;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class ResetPasswordRequestServiceTest extends TestCase
{
    private MailerInterface&MockObject $mailer;
    private UserServiceInterface&MockObject $userService;
    private ResetPasswordRequestRepository&MockObject $resetPasswordRequestRepository;
    private UserPasswordHasherInterface&MockObject $passwordHasher;
    private EntityManagerInterface&MockObject $entityManager;
    private ResetPasswordRequestService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->userService = $this->createMock(UserServiceInterface::class);
        $this->resetPasswordRequestRepository = $this->createMock(ResetPasswordRequestRepository::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(static fn ($def) => match ($def->key) {
            Settings::resetPasswordCodeExpiryMinutes()->key => 15,
            default => throw new \LogicException("Unexpected setting: {$def->key}"),
        });

        $this->service = new ResetPasswordRequestService(
            $this->mailer,
            $this->userService,
            $this->resetPasswordRequestRepository,
            $this->passwordHasher,
            $translator,
            new RequestStack(),
            $settings,
            $this->createMock(SessionRevokerInterface::class),
        );
        $this->service->setEntityManager($this->entityManager);
    }

    public function testRequestPasswordResetSavesAndSendsEmail(): void
    {
        $this->userService->method('existsByEmail')->willReturn(true);
        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ResetPasswordRequest::class));
        $this->entityManager->expects(self::once())->method('flush');
        $this->mailer->expects(self::once())->method('send');

        $dto = new RequestPasswordResetDto(email: 'user@example.com');

        $this->service->requestPasswordReset($dto);
    }

    public function testRequestPasswordResetSkipsUnknownEmail(): void
    {
        $this->userService->method('existsByEmail')->willReturn(false);
        $this->entityManager->expects(self::never())->method('persist');
        $this->mailer->expects(self::never())->method('send');

        $dto = new RequestPasswordResetDto(email: 'stranger@example.com');

        $this->service->requestPasswordReset($dto);
    }

    public function testResetPasswordDoesNothingWhenUserNotFound(): void
    {
        $this->userService->method('findByEmail')->willReturn(null);

        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $dto = new PasswordResetDto(email: 'unknown@example.com', token: 'some-token', password: 'newpassword');

        $this->service->resetPassword($dto);
    }

    public function testResetPasswordMarksTokenUsedWhenValid(): void
    {
        $user = $this->createMock(User::class);
        $resetRequest = $this->createMock(ResetPasswordRequest::class);

        $this->userService->method('findByEmail')->willReturn($user);
        $this->resetPasswordRequestRepository->method('findOneNotExpiredByEmailAndToken')->willReturn($resetRequest);
        $this->passwordHasher->method('hashPassword')->willReturn('hashed_password');

        $resetRequest->expects(self::once())->method('setUsedAt')->with(self::isInstanceOf(\DateTimeImmutable::class));
        $user->expects(self::once())->method('setPassword')->with('hashed_password');

        $this->entityManager->expects(self::atLeastOnce())->method('persist');
        $this->entityManager->expects(self::atLeastOnce())->method('flush');

        $dto = new PasswordResetDto(email: 'user@example.com', token: 'valid-token', password: 'newpassword');

        $this->service->resetPassword($dto);
    }

    public function testResetPasswordDoesNothingWhenTokenIsInvalid(): void
    {
        $user = $this->createMock(User::class);

        $this->userService->method('findByEmail')->willReturn($user);
        $this->resetPasswordRequestRepository->method('findOneNotExpiredByEmailAndToken')->willReturn(null);

        $user->expects(self::never())->method('setPassword');
        $this->entityManager->expects(self::never())->method('persist');
        $this->entityManager->expects(self::never())->method('flush');

        $dto = new PasswordResetDto(email: 'user@example.com', token: 'expired-token', password: 'newpassword');

        $this->service->resetPassword($dto);
    }
}
