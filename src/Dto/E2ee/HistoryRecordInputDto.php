<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class HistoryRecordInputDto
{
    public const int MAX_BYTES = 768 * 1024;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['e2ee:write'])]
    public ?int $counter = null;

    #[Assert\NotBlank]
    #[Base64Bytes(max: self::MAX_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $blob = null;

    #[Assert\NotBlank]
    #[Base64Bytes(length: \SODIUM_CRYPTO_SIGN_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $signature = null;
}
