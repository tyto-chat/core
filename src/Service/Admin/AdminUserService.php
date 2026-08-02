<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\AdminUserDetailDto;
use App\Dto\Admin\AdminUserPatchDto;
use App\Entity\User;
use App\Enum\Admin\AdminAuditAction;
use App\Enum\Community\CommunityRole;
use App\Enum\User\UserRole;
use App\Exception\Admin\CannotDeleteSelfException;
use App\Exception\Admin\DeleteConfirmationMismatchException;
use App\Exception\Admin\MissingEmailException;
use App\Exception\User\EmailAlreadyTakenException;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;
use App\Service\ApiKey\ApiKeyServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Gdpr\AccountDeletionServiceInterface;
use App\Service\Notification\PushSubscriptionServiceInterface;
use App\Service\User\ResetPasswordRequestServiceInterface;
use App\Service\User\TwoFactorServiceInterface;
use App\Service\User\UserServiceInterface;

class AdminUserService extends AbstractDoctrineService implements AdminUserServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly UserServiceInterface $userService,
        private readonly CommunityServiceInterface $communityService,
        private readonly ResetPasswordRequestServiceInterface $resetService,
        private readonly AccountDeletionServiceInterface $accountDeletionService,
        private readonly ApiKeyServiceInterface $apiKeyService,
        private readonly PushSubscriptionServiceInterface $pushSubscriptionService,
        private readonly AdminAuditLoggerInterface $auditLogger,
        private readonly TwoFactorServiceInterface $twoFactorService,
    ) {
    }

    #[\Override]
    public function provision(string $name, ?string $email, bool $isBot, array $communityIds): User
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only server admins can provision users.');

        if (!$isBot) {
            if (null === $email || '' === trim($email)) {
                throw new MissingEmailException();
            }
            if ($this->userService->existsByEmail($email)) {
                throw new EmailAlreadyTakenException($email);
            }
        }

        $communities = [];
        if (!$isBot) {
            foreach (array_unique($communityIds) as $cid) {
                $communities[] = $this->communityService->get($cid);
            }
        }

        $user = $isBot
            ? $this->userService->newBot($name)
            : $this->userService->createInvited($name, (string) $email);

        foreach ($communities as $community) {
            $this->communityService->addMember($community, $user, CommunityRole::Member, false);
        }

        if (!$isBot) {
            $this->resetService->sendInvitation($user);
        }

        $this->auditLogger->record(
            AdminAuditAction::UserCreate,
            'user',
            $user->getId(),
            ['isBot' => $isBot, 'communityIds' => array_map(static fn ($c) => $c->getId(), $communities)],
        );

        return $user;
    }

    #[\Override]
    public function patch(int $id, AdminUserPatchDto $dto): User
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only server admins can edit users.');

        $user = $this->userService->get($id);

        $changes = [];

        if (isset($dto->displayName)) {
            $value = trim($dto->displayName);
            $before = $user->getProfile()?->getName();
            if ($before !== $value) {
                $changes['displayName'] = ['from' => $before, 'to' => $value];
                $this->userService->adminSetDisplayName($user, $value);
            }
        }

        if (isset($dto->isAdmin)) {
            $hasAdmin = in_array(UserRole::Admin->value, $user->getRoles(), true);
            if ($dto->isAdmin !== $hasAdmin) {
                $roles = array_values(array_filter(
                    $user->getRoles(),
                    static fn (string $r): bool => UserRole::Admin->value !== $r,
                ));
                if ($dto->isAdmin) {
                    $roles[] = UserRole::Admin->value;
                }
                $this->userService->updateRoles($user, $roles);
                $this->auditLogger->record(
                    $dto->isAdmin ? AdminAuditAction::UserPromote : AdminAuditAction::UserDemote,
                    'user',
                    $user->getId(),
                );
            }
        }

        if (array_key_exists('displayName', $changes)) {
            $this->auditLogger->record(
                AdminAuditAction::UserEditProfile,
                'user',
                $user->getId(),
                ['changes' => $changes],
            );
        }

        return $user;
    }

    #[\Override]
    public function forceDelete(int $id, ?string $confirm): void
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only server admins can force-delete users.');

        $user = $this->userService->get($id);

        if ($user->getId() === $this->security->getUser()?->getId()) {
            throw new CannotDeleteSelfException('Use /api/me/account-deletion to delete your own account.');
        }

        if ($confirm !== $user->getEmail()) {
            throw new DeleteConfirmationMismatchException('Confirmation required: send {"confirm": "<target email>"} to force-delete this account.');
        }

        $this->accountDeletionService->purgeForUser($user);
        $this->auditLogger->record(AdminAuditAction::UserForceDelete, 'user', $id);
    }

    #[\Override]
    public function disableTwoFactor(int $id): User
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only server admins can disable another user\'s two-factor authentication.');

        $user = $this->userService->get($id);
        $this->twoFactorService->reset($user);
        $this->auditLogger->record(AdminAuditAction::UserTwoFactorDisable, 'user', $user->getId());

        return $user;
    }

    #[\Override]
    public function detail(User $user): AdminUserDetailDto
    {
        return AdminUserDetailDto::fromDetail(
            $user,
            $this->apiKeyService->countFor($user),
            $this->pushSubscriptionService->countFor($user),
        );
    }
}
