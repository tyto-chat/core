<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class KeyPackageInputDto
{
    public const int MAX_BYTES = 4096;

    #[Assert\NotBlank]
    #[Base64Bytes(max: self::MAX_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $data = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['e2ee:write'])]
    public ?int $expiresAt = null;
}
