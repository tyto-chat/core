<?php

declare(strict_types=1);

namespace App\Dto\Community;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateCommunityInviteDto
{
    #[Assert\Positive]
    public ?int $maxUses = null;

    #[Assert\GreaterThan('now')]
    public ?\DateTimeImmutable $expiresAt = null;
}
