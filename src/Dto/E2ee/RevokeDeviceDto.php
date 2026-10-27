<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class RevokeDeviceDto
{
    #[Assert\NotNull]
    #[Assert\Valid]
    #[Groups(['e2ee:write'])]
    public ?RevocationInputDto $revocation = null;
}
