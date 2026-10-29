<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class LeaveRequestInputDto
{
    #[Assert\NotBlank]
    #[Assert\Uuid(versions: [Assert\Uuid::V4_RANDOM], strict: true)]
    #[Assert\Regex(pattern: '/^[0-9a-f-]+$/')]
    #[Groups(['e2ee:write'])]
    public ?string $leaveRequestId = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['e2ee:write'])]
    public ?int $requestedAt = null;

    #[Assert\NotBlank]
    #[Base64Bytes(length: \SODIUM_CRYPTO_SIGN_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $signature = null;
}
