<?php

declare(strict_types=1);

namespace App\Dto\UserGroup;

use Symfony\Component\Validator\Constraints as Assert;

class AddGroupMemberDto
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $userId = null;
}
