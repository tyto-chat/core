<?php

declare(strict_types=1);

namespace App\Dto\Report;

use App\Enum\Moderation\ReportStatus;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class UpdateReportDto
{
    #[Assert\NotNull]
    public ReportStatus $status;

    #[Assert\Length(max: 2000)]
    public ?string $resolutionNote = null;

    #[Assert\Callback]
    public function validateCrossField(ExecutionContextInterface $context): void
    {
        if (ReportStatus::Open === $this->status) {
            $context->buildViolation('Reports cannot be reopened.')->atPath('status')->addViolation();
        }
    }
}
