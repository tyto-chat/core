<?php

declare(strict_types=1);

namespace App\Dto\CommunityMember;

use App\Enum\Community\CommunityRole;
use Symfony\Component\Validator\Constraints as Assert;

final class UpdateCommunityMemberRoleDto
{
    // Nullable + NotNull: an uninitialized typed property would 500 on read when the body omits `role`.
    #[Assert\NotNull]
    public ?CommunityRole $role = null;
}
