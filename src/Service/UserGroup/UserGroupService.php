<?php

declare(strict_types=1);

namespace App\Service\UserGroup;

use App\Dto\Notification\CreateNotificationDto;
use App\Dto\UserGroup\CreateUserGroupDto;
use App\Dto\UserGroup\UpdateUserGroupDto;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\GroupChannelPermission;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use App\Enum\Channel\ChannelRole;
use App\Enum\Notification\NotificationType;
use App\Exception\Channel\UserNotCommunityMemberException;
use App\Exception\UserGroup\CannotLeaveOwnedGroupException;
use App\Exception\UserGroup\ChannelNotInGroupCommunityException;
use App\Exception\UserGroup\InvalidGroupOwnerException;
use App\Exception\UserGroup\UserAlreadyGroupMemberException;
use App\Exception\UserGroup\UserGroupNotFoundException;
use App\Exception\UserGroup\UserNotGroupMemberException;
use App\Repository\GroupChannelPermissionRepository;
use App\Repository\UserGroupMemberRepository;
use App\Repository\UserGroupRepository;
use App\Security\SecurityContext;
use App\Security\Voter\UserGroupVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Notification\NotificationServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class UserGroupService extends AbstractDoctrineService implements UserGroupServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly UserGroupRepository $userGroupRepository,
        private readonly UserGroupMemberRepository $userGroupMemberRepository,
        private readonly GroupChannelPermissionRepository $groupChannelPermissionRepository,
        private readonly NotificationServiceInterface $notificationService,
        private readonly RealtimePublisherInterface $realtimePublisher,
        private readonly ChannelMembershipServiceInterface $channelMembershipService,
    ) {
    }

    private function publishAccessEvent(User $user, Channel $channel, Community $community, bool $granted): void
    {
        if (!$channel->isPrivate()) {
            return;
        }
        if (!$granted
            && ($this->channelMembershipService->isChannelMember($user, $channel)
                || null !== $this->getEffectiveChannelRole($user, $channel))) {
            return;
        }

        $this->realtimePublisher->publishUserEvent(
            (int) $user->getId(),
            $granted ? 'channel.access.granted' : 'channel.access.revoked',
            [
                'communityIdentifier' => (string) $community->getIdentifier(),
                'channelIdentifier' => (string) $channel->getIdentifier(),
            ],
        );
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function new(Community $community, CreateUserGroupDto $dto): UserGroup
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, "You don't have permission to create groups.");

        $group = new UserGroup();
        $group->setCommunity($community);
        $group->setName($dto->name);
        $group->setIcon($dto->icon);
        $group->setColor($dto->color);
        $group->setIsHidden($dto->isHidden);

        return $this->save($group);
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function update(UserGroup $group, UpdateUserGroupDto $dto, ?User $owner): UserGroup
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($group->getCommunity(), "You don't have permission to update groups.");

        if ($dto->isProvided('name')) {
            $group->setName($dto->name);
        }
        if ($dto->isProvided('icon')) {
            $group->setIcon($dto->icon);
        }
        if ($dto->isProvided('color')) {
            $group->setColor($dto->color);
        }
        if ($dto->isProvided('isHidden')) {
            $group->setIsHidden($dto->isHidden);
        }
        if ($dto->isProvided('ownerId')) {
            if (null !== $owner && !$this->userGroupMemberRepository->isMember($owner, $group)) {
                throw new InvalidGroupOwnerException('Owner must be a current member of the group.');
            }
            $group->setOwner($owner);
        }

        return $this->save($group);
    }

    /**
     * @throws UserGroupNotFoundException
     */
    #[\Override]
    public function getByIdentifier(string $identifier, Community $community): UserGroup
    {
        $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view groups.');

        $group = $this->userGroupRepository->findOneBy(['identifier' => $identifier, 'community' => $community]);

        // Deny-on-VIEW maps to NotFound — hidden groups must not leak existence.
        if (null === $group || !$this->security->isGranted(UserGroupVoter::VIEW, $group)) {
            throw new UserGroupNotFoundException(sprintf('Group "%s" not found.', $identifier));
        }

        return $group;
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function delete(UserGroup $group): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($group->getCommunity(), "You don't have permission to delete groups.");
        $this->removeAndFlush($group);
    }

    /** @return UserGroup[] */
    #[\Override]
    public function getAllForCommunity(Community $community): array
    {
        $currentUser = $this->security->currentUser('You must be signed in to view groups.');
        $isAdmin = $this->security->isCommunityAdmin($community);

        return $this->userGroupRepository->findVisibleByCommunity($community, $currentUser, $isAdmin);
    }

    /** @return UserGroup[] */
    #[\Override]
    public function getGroupsForUserInCommunity(User $user, Community $community): array
    {
        return $this->userGroupRepository->findGroupsForUser($user, $community);
    }

    /** @return array{group: UserGroup, memberCount: int, isOwner: bool}[] */
    #[\Override]
    public function getMyGroups(): array
    {
        $currentUser = $this->security->currentUser('You must be signed in to view your groups.');
        $groups = $this->userGroupRepository->findAllForUser($currentUser);
        $counts = $this->userGroupMemberRepository->countForGroups(
            array_map(static fn (UserGroup $g): int => (int) $g->getId(), $groups),
        );

        return array_map(static fn (UserGroup $g): array => [
            'group' => $g,
            'memberCount' => $counts[(int) $g->getId()] ?? 0,
            'isOwner' => $g->getOwnerId() === $currentUser->getId(),
        ], $groups);
    }

    /**
     * @throws AccessDeniedException
     * @throws UserNotCommunityMemberException
     */
    #[\Override]
    public function addMember(UserGroup $group, User $target): UserGroupMember
    {
        $this->security->throwAccessDeniedUnlessGranted(UserGroupVoter::MANAGE, $group, "You don't have permission to add members to this group.");

        $community = $group->getCommunity();
        if (!$this->communityMembership->isMember($target, $community)) {
            throw new UserNotCommunityMemberException('Target user is not a member of the community.');
        }

        if ($this->userGroupMemberRepository->findOneByUserAndGroup($target, $group)) {
            throw new UserAlreadyGroupMemberException('User is already a member of this group.');
        }

        $member = new UserGroupMember();
        $member->setUser($target);
        $member->setUserGroup($group);
        $member = $this->save($member);

        $this->notificationService->new(new CreateNotificationDto(
            recipient: $target,
            community: $community,
            communityIdentifier: (string) $community->getIdentifier(),
            type: NotificationType::GroupAdded,
            groupName: $group->getName(),
            groupIdentifier: (string) $group->getIdentifier(),
        ));

        $this->realtimePublisher->publishCommunityStructureChanged($community);
        foreach ($this->groupChannelPermissionRepository->findByGroup($group) as $perm) {
            $this->publishAccessEvent($target, $perm->getChannel(), $community, true);
        }

        return $member;
    }

    /**
     * @throws AccessDeniedException
     * @throws UserNotGroupMemberException
     */
    #[\Override]
    public function removeMember(UserGroup $group, User $target): void
    {
        $currentUser = $this->security->currentUser('You must be signed in to remove group members.');
        $isSelf = $currentUser->getId() === $target->getId();

        if (!$isSelf) {
            $this->security->throwAccessDeniedUnlessGranted(UserGroupVoter::MANAGE, $group, "You don't have permission to remove members from this group.");
        } elseif ($group->getOwnerId() === $target->getId()) {
            throw new CannotLeaveOwnedGroupException('Transfer ownership before leaving a group you own.');
        }

        $member = $this->userGroupMemberRepository->findOneByUserAndGroup($target, $group);
        if (!$member) {
            throw new UserNotGroupMemberException(sprintf('User "%d" is not a member of group "%s".', $target->getId(), $group->getIdentifier()));
        }

        if ($group->getOwnerId() === $target->getId()) {
            $group->setOwner(null);
            $this->save($group);
        }

        $this->removeAndFlush($member);

        $community = $group->getCommunity();
        $this->realtimePublisher->publishCommunityStructureChanged($community);
        foreach ($this->groupChannelPermissionRepository->findByGroup($group) as $perm) {
            $this->publishAccessEvent($target, $perm->getChannel(), $community, false);
        }

        if (!$isSelf) {
            $this->notificationService->new(new CreateNotificationDto(
                recipient: $target,
                community: $community,
                communityIdentifier: (string) $community->getIdentifier(),
                type: NotificationType::GroupRemoved,
                groupName: $group->getName(),
                groupIdentifier: (string) $group->getIdentifier(),
            ));
        }
    }

    /**
     * @throws AccessDeniedException
     * @throws InvalidGroupOwnerException
     */
    #[\Override]
    public function transferOwnership(UserGroup $group, User $newOwner): UserGroup
    {
        $this->security->throwAccessDeniedUnlessGranted(UserGroupVoter::MANAGE, $group, "You don't have permission to transfer ownership of this group.");

        if ($group->getOwnerId() === $newOwner->getId()) {
            throw new InvalidGroupOwnerException('User is already the owner of this group.');
        }

        if (null === $this->userGroupMemberRepository->findOneByUserAndGroup($newOwner, $group)) {
            throw new InvalidGroupOwnerException('New owner must be a member of the group.');
        }

        $group->setOwner($newOwner);
        $group = $this->save($group);

        $community = $group->getCommunity();
        $this->notificationService->new(new CreateNotificationDto(
            recipient: $newOwner,
            community: $community,
            communityIdentifier: (string) $community->getIdentifier(),
            type: NotificationType::GroupOwnershipTransferred,
            groupName: $group->getName(),
            groupIdentifier: (string) $group->getIdentifier(),
        ));

        return $group;
    }

    /** @return UserGroupMember[] */
    #[\Override]
    public function getMembers(UserGroup $group): array
    {
        $this->security->throwAccessDeniedUnlessGranted(UserGroupVoter::VIEW_MEMBERS, $group, 'You do not have permission to view members of this group.');

        return $this->userGroupMemberRepository->findByGroup($group);
    }

    /** @return GroupChannelPermission[] */
    #[\Override]
    public function getChannelPermissions(UserGroup $group): array
    {
        $this->security->throwAccessDeniedUnlessGranted(UserGroupVoter::MANAGE, $group, "You don't have permission to view group channel permissions.");

        return $this->groupChannelPermissionRepository->findByGroup($group);
    }

    /**
     * @throws AccessDeniedException
     * @throws ChannelNotInGroupCommunityException
     */
    #[\Override]
    public function setChannelPermission(UserGroup $group, Channel $channel, ChannelRole $role): GroupChannelPermission
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($group->getCommunity(), "You don't have permission to manage group channel permissions.");

        if ($channel->getCommunity()?->getId() !== $group->getCommunity()->getId()) {
            throw new ChannelNotInGroupCommunityException(sprintf('Channel "%s" does not belong to the group\'s community.', $channel->getIdentifier() ?? ''));
        }

        $permission = $this->groupChannelPermissionRepository->findOneByGroupAndChannel($group, $channel);
        if (!$permission) {
            $permission = new GroupChannelPermission();
            $permission->setUserGroup($group);
            $permission->setChannel($channel);
        }

        $permission->setRole($role);
        $saved = $this->save($permission);

        $community = $group->getCommunity();
        $this->realtimePublisher->publishCommunityStructureChanged($community);
        foreach ($this->userGroupMemberRepository->findByGroup($group) as $groupMember) {
            $this->publishAccessEvent($groupMember->getUser(), $channel, $community, true);
        }

        return $saved;
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function removeChannelPermission(UserGroup $group, Channel $channel): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($group->getCommunity(), "You don't have permission to manage group channel permissions.");

        $permission = $this->groupChannelPermissionRepository->findOneByGroupAndChannel($group, $channel);
        if ($permission) {
            $this->removeAndFlush($permission);

            $community = $group->getCommunity();
            $this->realtimePublisher->publishCommunityStructureChanged($community);
            foreach ($this->userGroupMemberRepository->findByGroup($group) as $groupMember) {
                $this->publishAccessEvent($groupMember->getUser(), $channel, $community, false);
            }
        }
    }

    #[\Override]
    public function getEffectiveChannelRole(User $user, Channel $channel): ?ChannelRole
    {
        return $this->groupChannelPermissionRepository->findHighestRoleForUserInChannel($user, $channel);
    }

    #[\Override]
    public function getEffectiveChannelRolesInCommunity(User $user, Community $community): array
    {
        return $this->groupChannelPermissionRepository->findRolesForUserInCommunity($user, $community);
    }

    #[\Override]
    public function removeMembershipsForUserInCommunity(User $user, Community $community): void
    {
        $this->userGroupRepository->clearOwnerForUserInCommunity($user, $community);
        $this->userGroupMemberRepository->deleteForUserInCommunity($user, $community);
    }

    #[\Override]
    public function countMembers(UserGroup $group): int
    {
        return $this->userGroupMemberRepository->countByGroup($group);
    }

    #[\Override]
    public function countMembersPerGroupInCommunity(Community $community): array
    {
        return $this->userGroupMemberRepository->countPerGroupInCommunity($community);
    }

    /** @return array<int, UserGroup[]> */
    #[\Override]
    public function findGroupMembershipsIndexedByUser(Community $community): array
    {
        return $this->userGroupMemberRepository->findGroupMembershipsIndexedByUser($community);
    }
}
