<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class ResetAccountDto
{
    #[Assert\NotBlank]
    #[Base64Bytes(max: IdentityBackupInputDto::MAX_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $identityBackup = null;

    #[Assert\NotBlank]
    #[Base64Bytes(length: \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $newPublicKey = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['e2ee:write'])]
    public ?int $generation = null;
}
