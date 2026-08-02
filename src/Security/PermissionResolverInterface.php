<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\Community\CommunityRole;

interface PermissionResolverInterface
{
    public function communityRole(User $user, Community $community): ?CommunityRole;

    public function isMember(User $user, Community $community): bool;

    public function isCommunityAdmin(User $user, Community $community): bool;

    public function isCommunityModerator(User $user, Community $community): bool;

    /** @return array<int, ChannelRole> channelId => direct membership role */
    public function directChannelRoles(User $user, Community $community): array;

    /** @return array<int, ChannelRole> channelId => group-derived role */
    public function groupChannelRoles(User $user, Community $community): array;

    public function effectiveChannelRole(User $user, Channel $channel): ?ChannelRole;
}
