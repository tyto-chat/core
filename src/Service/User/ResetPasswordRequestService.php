<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Dto\User\PasswordResetDto;
use App\Dto\User\RequestPasswordResetDto;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\ResetPasswordRequestRepository;
use App\Service\AbstractDoctrineService;
use App\Service\Security\SessionRevokerInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ResetPasswordRequestService extends AbstractDoctrineService implements ResetPasswordRequestServiceInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UserServiceInterface $userService,
        private readonly ResetPasswordRequestRepository $resetPasswordRequestRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
        private readonly SettingsServiceInterface $settings,
        private readonly SessionRevokerInterface $sessionRevoker,
    ) {
    }

    #[\Override]
    public function requestPasswordReset(RequestPasswordResetDto $dto): void
    {
        if (!$this->userService->existsByEmail($dto->email)) {
            return;
        }

        $expiryInMinutes = $this->settings->get(Settings::resetPasswordCodeExpiryMinutes());
        $request = new ResetPasswordRequest();
        $request->setEmail($dto->email);
        $request->setExpiresAt(new \DateTimeImmutable(sprintf('+%d minutes', $expiryInMinutes)));
        $this->save($request);

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        $this->mailer->send(
            (new TemplatedEmail())
                ->to($request->getEmail())
                ->subject($this->translator->trans('password_reset.subject', [], 'emails'))
                ->htmlTemplate('emails/password_reset.html.twig')
                ->locale($locale)
                ->context([
                    'resetPasswordRequest' => $request,
                    'expiryInMinutes' => $expiryInMinutes,
                ])
        );
    }

    #[\Override]
    public function resetPassword(PasswordResetDto $passwordResetDto): void
    {
        $user = $this->userService->findByEmail($passwordResetDto->email);
        if (!$user) {
            return;
        }

        $resetRequest = $this->resetPasswordRequestRepository->findOneNotExpiredByEmailAndToken($passwordResetDto->email, $passwordResetDto->token);
        if (null === $resetRequest) {
            return;
        }

        $resetRequest->setUsedAt(new \DateTimeImmutable());
        $this->save($resetRequest);

        $user->setPassword($this->passwordHasher->hashPassword($user, $passwordResetDto->password));
        $this->save($user);
        $this->sessionRevoker->revokeRefreshTokens($user);
    }

    #[\Override]
    public function isValid(string $email, string $token): bool
    {
        return null !== $this->resetPasswordRequestRepository->findOneNotExpiredByEmailAndToken($email, $token);
    }

    #[\Override]
    public function sendInvitation(User $user): void
    {
        $expiryInHours = $this->settings->get(Settings::invitationExpiryHours());
        $request = new ResetPasswordRequest();
        $request->setEmail($user->getEmail());
        $request->setExpiresAt(new \DateTimeImmutable(sprintf('+%d hours', $expiryInHours)));
        $this->save($request);

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';

        $this->mailer->send(
            (new TemplatedEmail())
                ->to($request->getEmail())
                ->subject($this->translator->trans('invitation.subject', [], 'emails'))
                ->htmlTemplate('emails/invitation.html.twig')
                ->locale($locale)
                ->context([
                    'resetPasswordRequest' => $request,
                    'expiryInHours' => $expiryInHours,
                    'user' => $user,
                ])
        );
    }
}
