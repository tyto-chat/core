<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Notification;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Notification>
 */
class NotificationVoter extends Voter
{
    public const string VIEW = 'NOTIFICATION_VIEW';
    public const string UPDATE = 'NOTIFICATION_UPDATE';

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::UPDATE], true) && $subject instanceof Notification;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $subject->getRecipient()?->getId() === $user->getId();
    }
}
