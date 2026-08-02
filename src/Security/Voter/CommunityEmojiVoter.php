<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Community;
use App\Entity\CommunityEmoji;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Service\Community\CommunityMembershipServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Community|CommunityEmoji>
 */
class CommunityEmojiVoter extends Voter
{
    public const string VIEW = 'COMMUNITY_EMOJI_VIEW';
    public const string MANAGE = 'COMMUNITY_EMOJI_MANAGE';

    public function __construct(
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, [self::VIEW, self::MANAGE], true)) {
            return false;
        }

        return $subject instanceof Community || $subject instanceof CommunityEmoji;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $community = $subject instanceof CommunityEmoji ? $subject->getCommunity() : $subject;
        if (!$community instanceof Community) {
            return false;
        }

        if (self::VIEW === $attribute && !$community->isPrivate()) {
            return true;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array(UserRole::Admin->value, $token->getRoleNames(), true)) {
            return true;
        }

        return match ($attribute) {
            self::VIEW => $this->communityMembershipService->isMember($user, $community),
            self::MANAGE => $this->communityMembershipService->isAdmin($user, $community),
            default => false,
        };
    }
}
