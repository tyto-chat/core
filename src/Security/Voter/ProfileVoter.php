<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Profile;
use App\Entity\User;
use App\Enum\User\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Profile>
 */
class ProfileVoter extends Voter
{
    public const string VIEW = 'PROFILE_VIEW';
    public const string UPDATE = 'PROFILE_UPDATE';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::UPDATE], true) && $subject instanceof Profile;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::UPDATE => $subject->getUser()?->getId() === $user->getId()
                || in_array(UserRole::Admin->value, $token->getRoleNames(), true),
            default => false,
        };
    }
}
