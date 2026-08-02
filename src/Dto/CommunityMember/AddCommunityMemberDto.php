<?php

declare(strict_types=1);

namespace App\Dto\CommunityMember;

use App\Enum\Community\CommunityRole;
use Symfony\Component\Validator\Constraints as Assert;

final class AddCommunityMemberDto
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public int $userId;

    public CommunityRole $role = CommunityRole::Member;
}
