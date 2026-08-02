<?php

declare(strict_types=1);

namespace App\Dto\Appeal;

use App\Enum\Moderation\AppealStatus;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UpdateAppealDto
{
    #[Assert\NotNull]
    public AppealStatus $status;

    #[Assert\Length(max: 2000)]
    public ?string $resolutionNote = null;

    #[Assert\Callback]
    public function validateStatus(ExecutionContextInterface $context): void
    {
        if (AppealStatus::Pending === $this->status) {
            $context->buildViolation('An appeal decision must be upheld or overturned.')->atPath('status')->addViolation();
        }
    }
}
