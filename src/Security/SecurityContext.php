<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Enum\User\UserRole;
use App\Exception\AccessDeniedException;
use App\Service\Community\CommunityMembershipServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class SecurityContext
{
    public function __construct(
        private Security $security,
        private CommunityMembershipServiceInterface $communityMembership,
    ) {
    }

    public function getUser(): ?User
    {
        $user = $this->security->getUser();
        assert($user instanceof User || null === $user);

        return $user;
    }

    /**
     * @throws AccessDeniedException
     */
    public function currentUser(string $message = 'Authentication required.'): User
    {
        $this->throwAccessDeniedUnlessAuthenticated($message);

        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }

    public function isGranted(string $attribute, ?object $subject = null): bool
    {
        return $this->security->isGranted($attribute, $subject);
    }

    public function isAuthenticated(): bool
    {
        return $this->security->isGranted(UserRole::User->value);
    }

    public function isAdmin(): bool
    {
        return $this->security->isGranted(UserRole::Admin->value);
    }

    public function isCommunityAdmin(?Community $community): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (null === $community) {
            return false;
        }

        $user = $this->getUser();

        return null !== $user && $this->communityMembership->isAdmin($user, $community);
    }

    public function isCommunityModOrAdmin(Community $community): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $user = $this->getUser();
        if (null === $user) {
            return false;
        }

        $role = $this->communityMembership->findRole($user, $community);

        return in_array($role, [CommunityRole::Moderator, CommunityRole::Admin], true);
    }

    /**
     * @throws AccessDeniedException
     */
    public function throwAccessDeniedUnlessAdmin(string $message = ''): void
    {
        if (!$this->isAdmin()) {
            throw new AccessDeniedException($message);
        }
    }

    /**
     * @throws AccessDeniedException
     */
    public function throwAccessDeniedUnlessCommunityAdmin(?Community $community, string $message = ''): void
    {
        if (!$this->isCommunityAdmin($community)) {
            throw new AccessDeniedException($message);
        }
    }

    /**
     * @throws AccessDeniedException
     */
    public function throwAccessDeniedUnlessCommunityModOrAdmin(Community $community, string $message = ''): void
    {
        if (!$this->isCommunityModOrAdmin($community)) {
            throw new AccessDeniedException($message);
        }
    }

    /**
     * @throws AccessDeniedException
     */
    public function throwAccessDeniedUnlessAuthenticated(string $message = ''): void
    {
        if (!$this->isAuthenticated()) {
            throw new AccessDeniedException($message);
        }
    }

    /**
     * @throws AccessDeniedException
     */
    public function throwAccessDeniedUnlessGranted(string $attribute, object $entity, string $message = ''): void
    {
        if (!$this->security->isGranted($attribute, $entity)) {
            throw new AccessDeniedException($message);
        }
    }

    /**
     * @throws AccessDeniedException
     */
    public function throwAccessDeniedIf(bool $condition, string $message = ''): void
    {
        if ($condition) {
            throw new AccessDeniedException($message);
        }
    }
}
