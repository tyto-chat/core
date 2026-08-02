<?php

declare(strict_types=1);

namespace App\Dto\Report;

use App\Enum\Moderation\ReportCategory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CreateReportDto
{
    #[Assert\Uuid]
    public ?string $messageId = null;

    #[Assert\Positive]
    public ?int $userId = null;

    /** Routes a user report to this community's queue; ignored for message reports. */
    #[Assert\Length(max: 100)]
    public ?string $communityIdentifier = null;

    #[Assert\NotNull]
    public ReportCategory $category;

    #[Assert\Length(max: 2000)]
    public ?string $comment = null;

    #[Assert\Callback]
    public function validateCrossField(ExecutionContextInterface $context): void
    {
        if ((null === $this->messageId) === (null === $this->userId)) {
            $context->buildViolation('Exactly one of messageId or userId is required.')->atPath('messageId')->addViolation();
        }

        if (ReportCategory::Other === $this->category && '' === trim($this->comment ?? '')) {
            $context->buildViolation('A comment is required for the "other" category.')->atPath('comment')->addViolation();
        }
    }
}
