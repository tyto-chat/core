<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class IdentityBackupInputDto
{
    #[Assert\NotBlank]
    #[Base64Bytes(max: self::MAX_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $blob = null;

    public const int MAX_BYTES = 48 * 1024;
}
