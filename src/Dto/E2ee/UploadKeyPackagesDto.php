<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Service\E2ee\E2eeServiceInterface;
use App\Validator\Constraint\Base64Bytes;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class UploadKeyPackagesDto
{
    /** @var list<KeyPackageInputDto> */
    #[Assert\Count(max: E2eeServiceInterface::MAX_ONE_TIME_KEY_PACKAGES)]
    #[Assert\Valid]
    #[Groups(['e2ee:write'])]
    public array $packages = [];

    #[Assert\Valid]
    #[Groups(['e2ee:write'])]
    public ?KeyPackageInputDto $lastResort = null;

    #[Base64Bytes(length: 32)]
    #[Groups(['e2ee:write'])]
    public ?string $lastResortReplaces = null;

    #[Assert\NotBlank]
    #[Base64Bytes(length: \SODIUM_CRYPTO_SIGN_BYTES)]
    #[Groups(['e2ee:write'])]
    public ?string $signature = null;
}
