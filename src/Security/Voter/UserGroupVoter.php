<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\User\UserRole;
use App\Repository\UserGroupMemberRepository;
use App\Service\Community\CommunityMembershipServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, UserGroup>
 */
class UserGroupVoter extends Voter
{
    public const string VIEW = 'USER_GROUP_VIEW';
    public const string VIEW_MEMBERS = 'USER_GROUP_VIEW_MEMBERS';
    public const string MANAGE = 'USER_GROUP_MANAGE';

    public function __construct(
        private readonly UserGroupMemberRepository $userGroupMemberRepository,
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::VIEW_MEMBERS, self::MANAGE], true)
            && $subject instanceof UserGroup;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array(UserRole::Admin->value, $token->getRoleNames(), true)) {
            return true;
        }

        if ($this->communityMembershipService->isAdmin($user, $subject->getCommunity())) {
            return true;
        }

        $isOwner = null !== $subject->getOwnerId() && $user->getId() === $subject->getOwnerId();
        if ($isOwner) {
            return true;
        }

        $isGroupMember = $this->userGroupMemberRepository->isMember($user, $subject);

        // MANAGE has no arm on purpose — only the short-circuits above grant it; here it must fall through to deny.
        return match ($attribute) {
            self::VIEW => $isGroupMember
                || (!$subject->getIsHidden() && $this->communityMembershipService->isMember($user, $subject->getCommunity())),
            self::VIEW_MEMBERS => $isGroupMember,
            default => false,
        };
    }
}
