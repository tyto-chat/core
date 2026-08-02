<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Channel;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\Community\CommunityRole;
use App\Enum\User\UserRole;
use App\Security\PermissionResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Channel>
 */
class ChannelVoter extends Voter
{
    public const string VIEW = 'CHANNEL_VIEW';
    public const string MODERATE = 'CHANNEL_MODERATE';
    public const string POST = 'CHANNEL_POST';
    public const string REPLY = 'CHANNEL_REPLY';
    public const string VIEW_MEMBERS = 'CHANNEL_VIEW_MEMBERS';
    public const string PIN = 'CHANNEL_PIN';

    public function __construct(private readonly PermissionResolverInterface $permissions)
    {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::MODERATE, self::POST, self::REPLY, self::VIEW_MEMBERS, self::PIN], true)
            && $subject instanceof Channel;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (in_array($attribute, [self::POST, self::REPLY, self::PIN], true) && $subject->isArchived()) {
            return false;
        }

        $community = $subject->getCommunity();
        $communityIsPrivate = null !== $community && $community->isPrivate();

        $user = $token->getUser();
        if (!$user instanceof User) {
            return self::VIEW === $attribute && !$subject->isPrivate() && !$communityIsPrivate;
        }

        if (in_array(UserRole::Admin->value, $token->getRoleNames(), true)) {
            return true;
        }

        $communityRole = null !== $community ? $this->permissions->communityRole($user, $community) : null;

        if (CommunityRole::Admin === $communityRole) {
            return true;
        }

        if (CommunityRole::Moderator === $communityRole) {
            return match ($attribute) {
                self::VIEW, self::POST, self::REPLY, self::VIEW_MEMBERS, self::PIN, self::MODERATE => true,
                default => false,
            };
        }

        $canSeeCommunity = !$communityIsPrivate || null !== $communityRole;

        if (self::VIEW === $attribute && !$subject->isPrivate() && $canSeeCommunity) {
            return true;
        }

        if (self::POST === $attribute && !$subject->isPrivate() && !$subject->isReadonly() && $canSeeCommunity) {
            return true;
        }

        if (self::REPLY === $attribute && !$subject->isPrivate() && $canSeeCommunity
            && (!$subject->isReadonly() || $subject->getAreReadonlyRepliesAllowed())) {
            return true;
        }

        $effectiveRole = $this->permissions->effectiveChannelRole($user, $subject);
        if (null === $effectiveRole) {
            return false;
        }

        // Stale channel/group rows must never grant access to an ex-member.
        if (null === $communityRole) {
            return false;
        }

        return match ($attribute) {
            self::VIEW, self::VIEW_MEMBERS => true,
            self::POST => ChannelRole::Moderator === $effectiveRole || !$subject->isReadonly(),
            self::REPLY => ChannelRole::Moderator === $effectiveRole || !$subject->isReadonly() || $subject->getAreReadonlyRepliesAllowed(),
            self::MODERATE, self::PIN => ChannelRole::Moderator === $effectiveRole,
            default => false,
        };
    }
}
