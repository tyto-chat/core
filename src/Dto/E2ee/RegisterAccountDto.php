<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class RegisterAccountDto
{
    #[Assert\NotBlank]
    #[Base64Bytes(length: \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $publicKey = null;
}
