<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ApiKey;
use App\Entity\User;
use App\Enum\User\UserRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, ApiKey>
 */
class ApiKeyVoter extends Voter
{
    public const string MANAGE = 'API_KEY_MANAGE';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof ApiKey;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (in_array(UserRole::Admin->value, $token->getRoleNames(), true)) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($subject->getUser()->getId() === $user->getId()) {
            return true;
        }

        $vote?->addReason(sprintf(
            'User %d cannot manage API key %d owned by user %d.',
            $user->getId() ?? 0,
            $subject->getId() ?? 0,
            $subject->getUser()->getId() ?? 0,
        ));

        return false;
    }
}
