<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Service\Community\CommunityMembershipServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Community>
 */
class CommunityVoter extends Voter
{
    public const string VIEW = 'COMMUNITY_VIEW';

    public function __construct(
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && $subject instanceof Community;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var Community $subject */
        if (!$subject->isPrivate()) {
            return true;
        }

        if (in_array(UserRole::Admin->value, $token->getRoleNames(), true)) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($this->communityMembershipService->isMember($user, $subject)) {
            return true;
        }

        $vote?->addReason(sprintf(
            'User #%d is not a member of community #%d.',
            (int) $user->getId(), $subject->getId(),
        ));

        return false;
    }
}
