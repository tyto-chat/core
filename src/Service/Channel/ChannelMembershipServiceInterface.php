<?php

declare(strict_types=1);

namespace App\Service\Channel;

use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Exception\Channel\ChannelMemberNotFoundException;

interface ChannelMembershipServiceInterface
{
    /** @return ChannelMember[] */
    public function getMembers(Channel $channel): array;

    public function addMember(Channel $channel, User $user, ChannelRole $role = ChannelRole::Member): ChannelMember;

    /**
     * @throws ChannelMemberNotFoundException
     */
    public function getMember(Channel $channel, User $user): ChannelMember;

    public function removeMember(ChannelMember $channelMember): void;

    public function updateMemberRole(ChannelMember $channelMember, ChannelRole $role): ChannelMember;

    /** @internal No authz — caller must gate. Consumed by voters / peer services. */
    public function findChannelMemberRole(User $user, Channel $channel): ?ChannelRole;

    /** @return array<int, ChannelRole> channel id → direct role, one query per community */
    public function findChannelMemberRolesInCommunity(User $user, Community $community): array;

    /**
     * @internal No authz — caller must gate. User IDs of explicit channel members.
     *
     * @return int[]
     */
    public function getMemberUserIds(Channel $channel): array;

    /** @internal No authz — caller must gate. Consumed by voters / peer services. */
    public function isChannelMember(User $user, Channel $channel): bool;

    /** @internal No authz — caller must gate. Consumed by voters / peer services. */
    public function isChannelModerator(User $user, Channel $channel): bool;

    /** @internal No authz — caller must gate. Consumed by ModerationService. */
    public function findModeratorMembershipInCommunity(User $user, Community $community): ?ChannelMember;

    /** @internal No authz — caller must gate. Drops every explicit channel membership the user holds in the community (leave/kick/ban cleanup). */
    public function removeAllForUserInCommunity(User $user, Community $community): void;
}
