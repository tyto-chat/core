<?php

declare(strict_types=1);

namespace App\Security;

use App\Dto\Conversation\PolicyDecision;
use App\Entity\User;
use App\Enum\Conversation\PolicyOutcome;
use App\Enum\Conversation\PolicyReason;
use App\Enum\Settings\EncryptedDmsMode;
use App\Service\E2ee\E2eeServiceInterface;
use App\Service\User\UserPreferenceServiceInterface;

final readonly class ConversationPolicy implements ConversationPolicyInterface
{
    public function __construct(
        private E2eeServiceInterface $e2ee,
        private UserPreferenceServiceInterface $preferences,
    ) {
    }

    #[\Override]
    public function decide(User $creator, array $others): PolicyDecision
    {
        $mode = $this->e2ee->mode();
        $participants = [$creator, ...$others];
        $refusing = $this->idsWhere($participants, fn (User $u): bool => $this->preferences->getForUser($u)->refusesPlainDms());
        $creatorRefuses = in_array((int) $creator->getId(), $refusing, true);
        $required = EncryptedDmsMode::Required === $mode;
        $mustEncrypt = $required || [] !== $refusing;
        $refusalReason = $creatorRefuses ? PolicyReason::SelfRefuses : PolicyReason::ParticipantRefuses;

        if (count($others) > 1) {
            return match (true) {
                $required => new PolicyDecision(PolicyOutcome::Blocked, true, PolicyReason::GroupNotAvailable, $refusing, []),
                [] !== $refusing => new PolicyDecision(PolicyOutcome::Blocked, true, $refusalReason, $refusing, []),
                default => new PolicyDecision(PolicyOutcome::Plain, false, EncryptedDmsMode::Disabled === $mode ? PolicyReason::OperatorDisabled : null, [], []),
            };
        }

        if (EncryptedDmsMode::Disabled === $mode) {
            return [] === $refusing
                ? new PolicyDecision(PolicyOutcome::Plain, false, PolicyReason::OperatorDisabled, [], [])
                : new PolicyDecision(PolicyOutcome::Blocked, true, $refusalReason, $refusing, []);
        }

        $unreachable = $this->idsWhere($participants, fn (User $u): bool => $u->isBot() || !$this->e2ee->isReachable($u));
        $reason = $required ? PolicyReason::OperatorRequired : ($mustEncrypt ? $refusalReason : null);

        return match (true) {
            [] === $unreachable => new PolicyDecision(PolicyOutcome::Encrypted, $mustEncrypt, $reason, $refusing, []),
            $mustEncrypt => new PolicyDecision(PolicyOutcome::Blocked, true, $reason, $refusing, $unreachable),
            default => new PolicyDecision(PolicyOutcome::PlainFallback, false, null, [], $unreachable),
        };
    }

    /**
     * @param list<User>           $users
     * @param callable(User): bool $predicate
     *
     * @return list<int>
     */
    private function idsWhere(array $users, callable $predicate): array
    {
        $ids = [];
        foreach ($users as $user) {
            if ($predicate($user)) {
                $ids[] = (int) $user->getId();
            }
        }

        return $ids;
    }
}
