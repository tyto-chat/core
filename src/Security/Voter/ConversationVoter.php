<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Conversation;
use App\Entity\User;
use App\Service\Conversation\ConversationServiceInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Conversation>
 */
class ConversationVoter extends Voter
{
    public const string VIEW = 'CONVERSATION_VIEW';
    public const string WRITE = 'CONVERSATION_WRITE';

    public function __construct(
        private readonly ConversationServiceInterface $conversationService,
    ) {
    }

    #[\Override]
    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::WRITE], true)
            && $subject instanceof Conversation;
    }

    #[\Override]
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // Deliberately no ROLE_ADMIN bypass — DMs are participant-only, even for global admins.
        return $this->conversationService->isMember($subject, $user);
    }
}
