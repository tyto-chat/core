<?php

declare(strict_types=1);

namespace App\Dto\User;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final class BlockUserDto
{
    #[Assert\NotNull]
    #[Assert\Positive]
    #[Groups(['user_blocks:write'])]
    public ?int $userId = null;
}
