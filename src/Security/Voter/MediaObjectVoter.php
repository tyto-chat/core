<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\MediaObject;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Service\Community\CommunityEmojiServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\User\ProfileServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, MediaObject>
 */
class MediaObjectVoter extends Voter
{
    public const string VIEW = 'MEDIA_VIEW';
    public const string DELETE = 'MEDIA_DELETE';
    public const string LINK = 'MEDIA_LINK';

    public function __construct(
        private readonly ProfileServiceInterface $profileService,
        private readonly CommunityServiceInterface $communityService,
        private readonly CommunityMembershipServiceInterface $communityMembershipService,
        private readonly ConversationServiceInterface $conversationService,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly CommunityEmojiServiceInterface $communityEmojiService,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::DELETE, self::LINK], true) && $subject instanceof MediaObject;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (self::DELETE === $attribute) {
            return $this->canDelete($subject, $user, $token);
        }

        if (self::LINK === $attribute) {
            return $this->canLink($subject, $user);
        }

        if ($user instanceof User && in_array(UserRole::Admin->value, $user->getRoles(), true)) {
            return true;
        }

        return match ($subject->type) {
            'avatar' => null !== $this->profileService->findByAvatar($subject),
            'logo' => $this->canViewLogo($subject, $user),
            'community_emoji' => $this->canViewCommunityEmoji($subject, $user),
            'attachment' => $this->canViewAttachment($subject, $user, $token),
            default => false,
        };
    }

    private function canViewLogo(MediaObject $subject, mixed $user): bool
    {
        $community = $this->communityService->findByLogo($subject);
        if (null === $community) {
            return false;
        }
        if (!$community->isPrivate()) {
            return true;
        }

        return $user instanceof User && $this->communityMembershipService->isMember($user, $community);
    }

    private function canViewCommunityEmoji(MediaObject $subject, mixed $user): bool
    {
        $community = $this->communityEmojiService->findByImage($subject)?->getCommunity();
        if (null === $community) {
            return false;
        }
        if (!$community->isPrivate()) {
            return true;
        }

        return $user instanceof User && $this->communityMembershipService->isMember($user, $community);
    }

    private function canViewAttachment(MediaObject $subject, mixed $user, TokenInterface $token): bool
    {
        if ($user instanceof User && $subject->getCreatedBy()?->getId() === $user->getId()) {
            return true;
        }

        if (null !== $subject->message) {
            $channel = $subject->message->getChannel();
            if (null !== $channel) {
                return $this->accessDecisionManager->decide($token, [ChannelVoter::VIEW], $channel);
            }

            $conversation = $subject->message->getConversation();
            if (null !== $conversation && $user instanceof User) {
                return $this->conversationService->isMember($conversation, $user);
            }
        }

        return false;
    }

    private function canLink(MediaObject $subject, mixed $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        if (in_array(UserRole::Admin->value, $user->getRoles(), true)) {
            return true;
        }

        return $subject->getCreatedBy()?->getId() === $user->getId();
    }

    private function canDelete(MediaObject $subject, mixed $user, TokenInterface $token): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        if (in_array(UserRole::Admin->value, $user->getRoles(), true)) {
            return true;
        }

        $isOwner = $subject->getCreatedBy()?->getId() === $user->getId();

        if (null === $subject->message) {
            return $isOwner;
        }

        if ($isOwner) {
            return true;
        }

        $channel = $subject->message->getChannel();
        if (null !== $channel) {
            return $this->accessDecisionManager->decide($token, [ChannelVoter::MODERATE], $channel);
        }

        // DM attachments: uploader-only delete — intentional, there are no DM moderators.
        return false;
    }
}
