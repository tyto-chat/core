<?php

declare(strict_types=1);

namespace App\Service\Gdpr;

use App\Entity\User;
use App\Exception\User\AccountAlreadyPendingDeletionException;
use App\Exception\User\AccountNotPendingDeletionException;
use App\Repository\RecoveryCodeRepository;
use App\Repository\UserRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\ApiKey\ApiKeyServiceInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Notification\PushSubscriptionServiceInterface;
use App\Service\Security\SessionRevokerInterface;

class AccountDeletionService extends AbstractDoctrineService implements AccountDeletionServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly UserRepository $userRepository,
        private readonly ApiKeyServiceInterface $apiKeyService,
        private readonly PushSubscriptionServiceInterface $pushSubscriptionService,
        private readonly MediaObjectServiceInterface $mediaObjectService,
        private readonly RecoveryCodeRepository $recoveryCodeRepository,
        private readonly SessionRevokerInterface $sessionRevoker,
    ) {
    }

    #[\Override]
    public function requestDeletion(): User
    {
        $user = $this->security->currentUser('You must be signed in to delete your account.');

        if ($user->isPendingDeletion()) {
            throw new AccountAlreadyPendingDeletionException('This account is already scheduled for deletion.');
        }

        $user->setDeletionRequestedAt(new \DateTimeImmutable());

        $this->apiKeyService->revokeAllFor($user);
        $this->pushSubscriptionService->removeAllFor($user);

        $this->flush();

        $this->logger?->info('gdpr.deletion.requested', [
            'channel' => 'gdpr',
            'user_id' => $user->getId(),
        ]);

        return $user;
    }

    #[\Override]
    public function cancelDeletion(): User
    {
        $user = $this->security->currentUser('You must be signed in to cancel deletion.');

        if (!$user->isPendingDeletion()) {
            throw new AccountNotPendingDeletionException('This account is not scheduled for deletion.');
        }

        $user->setDeletionRequestedAt(null);
        $this->flush();

        $this->logger?->info('gdpr.deletion.cancelled', [
            'channel' => 'gdpr',
            'user_id' => $user->getId(),
        ]);

        return $user;
    }

    #[\Override]
    public function findPurgeDateForCurrentUser(): ?\DateTimeImmutable
    {
        $user = $this->security->getUser();
        if (null === $user || !$user->isPendingDeletion()) {
            return null;
        }

        return $user->getDeletionRequestedAt()?->modify('+'.self::GRACE_PERIOD_DAYS.' days');
    }

    #[\Override]
    public function purgeExpired(): int
    {
        $cutoff = (new \DateTimeImmutable())->modify('-'.self::GRACE_PERIOD_DAYS.' days');
        $users = $this->userRepository->findExpiredForPurge($cutoff);

        $count = 0;
        foreach ($users as $user) {
            $this->anonymiseInPlace($user);
            ++$count;
        }
        if (0 !== $count) {
            $this->flush();
        }

        return $count;
    }

    #[\Override]
    public function purgeForUser(User $user): void
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only server admins can force-purge an account.');

        $this->apiKeyService->revokeAllFor($user);
        $this->pushSubscriptionService->removeAllFor($user);

        if (!$user->isPendingDeletion()) {
            $user->setDeletionRequestedAt(new \DateTimeImmutable());
        }

        $this->anonymiseInPlace($user);
        $this->flush();
    }

    private function anonymiseInPlace(User $user): void
    {
        $id = $user->getId() ?? 0;

        // Refresh tokens are keyed by the current email — revoke before the swap.
        $this->sessionRevoker->revokeRefreshTokens($user);

        $user->setEmail(sprintf('deleted-%d@invalid.local', $id));
        $user->setPassword(bin2hex(random_bytes(32)));
        $user->setIsBot(true);
        $user->setEmailNotifications(false);
        $user->setRoles([]);
        $user->setTwoFactorSecret(null);
        $user->setTotpEnabledAt(null);
        $user->setTotpLastUsedTimestep(null);
        $this->recoveryCodeRepository->deleteAllForUser($user);
        // deletionRequestedAt stays set — it marks the row as purge-anonymised for audits.

        $profile = $user->getProfile();
        if (null !== $profile) {
            $profile->setName('Deleted user');
            $avatar = $profile->getAvatar();
            $profile->setAvatar(null);
            if (null !== $avatar) {
                $this->mediaObjectService->delete($avatar);
            }
        }

        $this->logger?->info('gdpr.deletion.purged', [
            'channel' => 'gdpr',
            'user_id' => $id,
        ]);
    }
}
