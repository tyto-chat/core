<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Enum\Moderation\ModerationActionType;
use App\Enum\User\UserRole;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Community|Channel|ModerationAction>
 */
class ModerationVoter extends Voter
{
    public const string WARN = 'MODERATION_WARN';
    public const string TIMEOUT = 'MODERATION_TIMEOUT';
    public const string BAN = 'MODERATION_BAN';
    public const string SERVER_BAN = 'MODERATION_SERVER_BAN';
    public const string LIFT = 'MODERATION_LIFT';
    public const string VIEW = 'MODERATION_VIEW';

    public function __construct(
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
        private readonly ChannelMembershipServiceInterface $channelMembershipService,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::WARN, self::TIMEOUT, self::BAN, self::SERVER_BAN, self::LIFT, self::VIEW], true)
            && ($subject instanceof Community || $subject instanceof Channel || $subject instanceof ModerationAction);
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $isAdmin = in_array(UserRole::Admin->value, $token->getRoleNames(), true);

        return match ($attribute) {
            self::WARN => $subject instanceof Community && $this->isCommunityModOrAdmin($user, $isAdmin, $subject),
            self::BAN => $subject instanceof Community && $this->isCommunityAdmin($user, $isAdmin, $subject),
            self::SERVER_BAN => $isAdmin,
            self::TIMEOUT => $this->canTimeout($user, $isAdmin, $subject),
            self::LIFT => $subject instanceof ModerationAction && $this->canLift($user, $isAdmin, $subject),
            self::VIEW => $subject instanceof ModerationAction && $this->canView($user, $isAdmin, $subject),
            default => false,
        };
    }

    private function canTimeout(User $user, bool $isAdmin, mixed $subject): bool
    {
        if ($subject instanceof Channel) {
            return $this->isChannelMod($user, $isAdmin, $subject);
        }

        return $subject instanceof Community && $this->isCommunityModOrAdmin($user, $isAdmin, $subject);
    }

    private function canLift(User $user, bool $isAdmin, ModerationAction $action): bool
    {
        if ($isAdmin) {
            return true;
        }

        if (ModerationActionType::ServerBan === $action->getType()) {
            return false;
        }

        if (ModerationActionType::Ban === $action->getType()) {
            return $this->isCommunityAdmin($user, $isAdmin, $action->getCommunity());
        }

        if ($this->isCommunityModOrAdmin($user, $isAdmin, $action->getCommunity())) {
            return true;
        }

        $channel = $action->getChannel();

        return null !== $channel && $this->channelMembershipService->isChannelModerator($user, $channel);
    }

    private function canView(User $user, bool $isAdmin, ModerationAction $action): bool
    {
        if ($this->isCommunityModOrAdmin($user, $isAdmin, $action->getCommunity())) {
            return true;
        }

        $channel = $action->getChannel();

        return null !== $channel && $this->channelMembershipService->isChannelModerator($user, $channel);
    }

    private function isChannelMod(User $user, bool $isAdmin, Channel $channel): bool
    {
        if ($this->isCommunityModOrAdmin($user, $isAdmin, $channel->getCommunity())) {
            return true;
        }

        return $this->channelMembershipService->isChannelModerator($user, $channel);
    }

    private function isCommunityModOrAdmin(User $user, bool $isAdmin, ?Community $community): bool
    {
        if ($isAdmin) {
            return true;
        }
        if (null === $community) {
            return false;
        }

        return $this->communityMembershipService->isAdmin($user, $community)
            || $this->communityMembershipService->isModerator($user, $community);
    }

    private function isCommunityAdmin(User $user, bool $isAdmin, Community $community): bool
    {
        return $isAdmin || $this->communityMembershipService->isAdmin($user, $community);
    }
}
