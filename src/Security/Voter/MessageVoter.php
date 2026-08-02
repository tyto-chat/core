<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Message;
use App\Entity\User;
use App\Enum\Message\MessageKind;
use App\Enum\User\UserRole;
use App\Service\Community\CommunityMembershipServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Message>
 */
class MessageVoter extends Voter
{
    public const string VIEW = 'MESSAGE_VIEW';
    public const string UPDATE = 'MESSAGE_UPDATE';
    public const string DELETE = 'MESSAGE_DELETE';

    public function __construct(
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::UPDATE, self::DELETE], true) && $subject instanceof Message;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            if (self::VIEW !== $attribute) {
                return false;
            }
            $channel = $subject->getChannel();
            if (null !== $channel) {
                return $this->accessDecisionManager->decide($token, [ChannelVoter::VIEW], $channel);
            }

            return false;
        }

        $isSystem = MessageKind::System === $subject->getKind();

        if (self::UPDATE === $attribute && $isSystem) {
            return false;
        }

        $channel = $subject->getChannel();
        $conversation = $subject->getConversation();

        // Must stay BEFORE the ROLE_ADMIN short-circuit — global admins get no access to DMs they aren't part of.
        if (null !== $conversation) {
            return match ($attribute) {
                self::VIEW => $this->accessDecisionManager->decide($token, [ConversationVoter::VIEW], $conversation),
                self::UPDATE, self::DELETE => $subject->getCreatedBy()?->getId() === $user->getId(),
                default => false,
            };
        }

        if (in_array($attribute, [self::UPDATE, self::DELETE], true) && true === $channel?->isArchived()) {
            return false;
        }

        if (in_array(UserRole::Admin->value, $token->getRoleNames(), true)) {
            return true;
        }

        if (self::DELETE === $attribute && $isSystem) {
            $community = $subject->getChannel()?->getCommunity();

            return null !== $community
                && $this->communityMembershipService->isAdmin($user, $community);
        }

        if (null !== $channel) {
            return match ($attribute) {
                self::VIEW => $this->accessDecisionManager->decide($token, [ChannelVoter::VIEW], $channel),
                self::UPDATE, self::DELETE => $this->canUpdateChannelMessage($subject, $user, $token),
                default => false,
            };
        }

        return false;
    }

    private function canUpdateChannelMessage(Message $message, User $user, TokenInterface $token): bool
    {
        if ($message->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }

        $channel = $message->getChannel();

        return null !== $channel && $this->accessDecisionManager->decide($token, [ChannelVoter::MODERATE], $channel);
    }
}
