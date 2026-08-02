<?php

declare(strict_types=1);

namespace App\Dto\UserGroup;

use App\Enum\Channel\ChannelRole;
use Symfony\Component\Validator\Constraints as Assert;

class SetGroupChannelPermissionDto
{
    #[Assert\NotNull]
    public ?ChannelRole $role = null;
}
