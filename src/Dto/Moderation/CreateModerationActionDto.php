<?php

declare(strict_types=1);

namespace App\Dto\Moderation;

use App\Enum\Moderation\ModerationActionType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CreateModerationActionDto
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public int $targetUserId;

    #[Assert\NotNull]
    public ModerationActionType $type;

    public ?string $reason = null;

    public ?\DateTimeInterface $expiresAt = null;

    /** Channel identifier — only valid for type=timeout */
    public ?string $channelIdentifier = null;

    #[Assert\Callback]
    public function validateCrossField(ExecutionContextInterface $context): void
    {
        if (ModerationActionType::Warn === $this->type) {
            if (null !== $this->expiresAt) {
                $context->buildViolation('expiresAt must be null for warn actions.')->atPath('expiresAt')->addViolation();
            }
            if (null !== $this->channelIdentifier) {
                $context->buildViolation('channelIdentifier must be null for warn actions.')->atPath('channelIdentifier')->addViolation();
            }
        }

        if (ModerationActionType::Timeout === $this->type && null === $this->expiresAt) {
            $context->buildViolation('expiresAt is required for timeout actions.')->atPath('expiresAt')->addViolation();
        }

        if (null !== $this->expiresAt) {
            $now = new \DateTimeImmutable();
            if ($this->expiresAt <= $now) {
                $context->buildViolation('expiresAt must be in the future.')->atPath('expiresAt')->addViolation();
            } elseif ($this->expiresAt > $now->modify('+1 year')) {
                $context->buildViolation('expiresAt must be at most one year in the future.')->atPath('expiresAt')->addViolation();
            }
        }

        if (ModerationActionType::Ban === $this->type && null !== $this->channelIdentifier) {
            $context->buildViolation('channelIdentifier must be null for ban actions.')->atPath('channelIdentifier')->addViolation();
        }

        if (ModerationActionType::ServerBan === $this->type && null !== $this->channelIdentifier) {
            $context->buildViolation('channelIdentifier must be null for server-ban actions.')->atPath('channelIdentifier')->addViolation();
        }
    }
}
