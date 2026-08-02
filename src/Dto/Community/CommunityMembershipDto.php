<?php

declare(strict_types=1);

namespace App\Dto\Community;

use Symfony\Component\Serializer\Attribute\Groups;

class CommunityMembershipDto
{
    /** Real CommunityMember role, or null if the caller has no membership row. No admin bypass. */
    #[Groups(['community_membership:read'])]
    public ?string $role = null;

    /** True only when the caller holds a real CommunityMember row. No admin bypass. */
    #[Groups(['community_membership:read'])]
    public bool $hasMembership = false;

    /** @var array<int, string> channel id => 'member'|'moderator', merged direct + group-derived */
    #[Groups(['community_membership:read'])]
    public array $channelRoles = [];
}
