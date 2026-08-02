<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Reaction;
use App\Entity\User;
use App\Enum\User\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Reaction>
 */
class ReactionVoter extends Voter
{
    public const string DELETE = 'REACTION_DELETE';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::DELETE === $attribute && $subject instanceof Reaction;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return in_array(UserRole::Admin->value, $token->getRoleNames(), true)
            || $subject->getCreatedBy()?->getId() === $user->getId();
    }
}
