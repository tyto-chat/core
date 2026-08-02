<?php

declare(strict_types=1);

namespace App\Service\UserGroup;

use App\Dto\UserGroup\CreateUserGroupDto;
use App\Dto\UserGroup\UpdateUserGroupDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\GroupChannelPermission;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use App\Enum\Channel\ChannelRole;
use App\Exception\UserGroup\InvalidGroupOwnerException;
use App\Exception\UserGroup\UserGroupNotFoundException;
use App\Exception\UserGroup\UserNotGroupMemberException;

interface UserGroupServiceInterface
{
    public function new(Community $community, CreateUserGroupDto $dto): UserGroup;

    public function update(UserGroup $group, UpdateUserGroupDto $dto, ?User $owner): UserGroup;

    /**
     * @throws UserGroupNotFoundException
     */
    public function getByIdentifier(string $identifier, Community $community): UserGroup;

    public function delete(UserGroup $group): void;

    /**
     * @return UserGroup[]
     */
    public function getAllForCommunity(Community $community): array;

    /**
     * @internal No authz — caller must gate. Consumed by peer services / voters.
     *
     * @return UserGroup[]
     */
    public function getGroupsForUserInCommunity(User $user, Community $community): array;

    /**
     * @return array{group: UserGroup, memberCount: int, isOwner: bool}[]
     */
    public function getMyGroups(): array;

    public function addMember(UserGroup $group, User $target): UserGroupMember;

    /**
     * @throws UserNotGroupMemberException
     */
    public function removeMember(UserGroup $group, User $target): void;

    /**
     * @throws InvalidGroupOwnerException
     */
    public function transferOwnership(UserGroup $group, User $newOwner): UserGroup;

    /** @return UserGroupMember[] */
    public function getMembers(UserGroup $group): array;

    /** @return GroupChannelPermission[] */
    public function getChannelPermissions(UserGroup $group): array;

    public function setChannelPermission(UserGroup $group, Channel $channel, ChannelRole $role): GroupChannelPermission;

    public function removeChannelPermission(UserGroup $group, Channel $channel): void;

    /**
     * @internal No authz — caller must gate. Consumed by ChannelVoter.
     */
    public function getEffectiveChannelRole(User $user, Channel $channel): ?ChannelRole;

    /**
     * @return array<int, ChannelRole> channel id → highest group-derived role, one query per community
     *
     * @internal No authz — caller must gate. Consumed by CommunityMembershipProvider.
     */
    public function getEffectiveChannelRolesInCommunity(User $user, Community $community): array;

    /** @internal No authz — caller must gate. Drops every group membership the user holds in the community (leave/kick/ban cleanup). */
    public function removeMembershipsForUserInCommunity(User $user, Community $community): void;

    /**
     * @internal No authz — caller must gate. Consumed by normalizers / counters.
     */
    public function countMembers(UserGroup $group): int;

    /**
     * @return array<int, int> group id → member count, one query per community (empty groups absent)
     *
     * @internal No authz — caller must gate. Consumed by UserGroupNormalizer.
     */
    public function countMembersPerGroupInCommunity(Community $community): array;

    /**
     * @internal No authz — caller must gate. Consumed by CommunityMemberNormalizer.
     *
     * @return array<int, UserGroup[]>
     */
    public function findGroupMembershipsIndexedByUser(Community $community): array;
}
