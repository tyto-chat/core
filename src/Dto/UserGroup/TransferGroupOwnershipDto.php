<?php

declare(strict_types=1);

namespace App\Dto\UserGroup;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class TransferGroupOwnershipDto
{
    #[Groups(['user_group:write'])]
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $userId = null;
}
