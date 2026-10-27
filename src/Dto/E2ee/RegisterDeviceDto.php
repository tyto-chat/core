<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class RegisterDeviceDto
{
    #[Assert\NotBlank]
    #[Assert\Uuid(versions: [Assert\Uuid::V4_RANDOM], strict: true)]
    #[Assert\Regex(pattern: '/^[0-9a-f-]+$/')]
    #[Groups(['e2ee:write'])]
    public ?string $deviceId = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    #[Groups(['e2ee:write'])]
    public ?string $name = null;

    #[Assert\NotBlank]
    #[Base64Bytes(length: \SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $publicKey = null;

    #[Assert\NotBlank]
    #[Assert\Url(requireTld: false)]
    #[Assert\Length(max: 255)]
    #[Groups(['e2ee:write'])]
    public ?string $server = null;

    #[Assert\NotBlank]
    #[Base64Bytes(length: \SODIUM_CRYPTO_SIGN_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $credential = null;
}
