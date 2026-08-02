<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\Community\CommunityRole;
use App\Repository\ChannelMemberRepository;
use App\Repository\CommunityMemberRepository;
use App\Repository\GroupChannelPermissionRepository;
use Symfony\Contracts\Service\ResetInterface;

final class PermissionResolver implements PermissionResolverInterface, ResetInterface
{
    /**
     * @var array<string, array{
     *     role: ?CommunityRole,
     *     channelRoles: array<int, ChannelRole>,
     *     groupRoles: array<int, ChannelRole>,
     * }>
     */
    private array $memo = [];

    public function __construct(
        private readonly CommunityMemberRepository $communityMembers,
        private readonly ChannelMemberRepository $channelMembers,
        private readonly GroupChannelPermissionRepository $groupChannelPermissions,
    ) {
    }

    #[\Override]
    public function communityRole(User $user, Community $community): ?CommunityRole
    {
        return $this->load($user, $community)['role'];
    }

    #[\Override]
    public function isMember(User $user, Community $community): bool
    {
        return null !== $this->communityRole($user, $community);
    }

    #[\Override]
    public function isCommunityAdmin(User $user, Community $community): bool
    {
        return CommunityRole::Admin === $this->communityRole($user, $community);
    }

    #[\Override]
    public function isCommunityModerator(User $user, Community $community): bool
    {
        return CommunityRole::Moderator === $this->communityRole($user, $community);
    }

    #[\Override]
    public function directChannelRoles(User $user, Community $community): array
    {
        return $this->load($user, $community)['channelRoles'];
    }

    #[\Override]
    public function groupChannelRoles(User $user, Community $community): array
    {
        return $this->load($user, $community)['groupRoles'];
    }

    #[\Override]
    public function effectiveChannelRole(User $user, Channel $channel): ?ChannelRole
    {
        $community = $channel->getCommunity();
        if (null === $community) {
            return null;
        }
        $loaded = $this->load($user, $community);
        $channelId = (int) $channel->getId();

        return ChannelRole::highest(
            $loaded['channelRoles'][$channelId] ?? null,
            $loaded['groupRoles'][$channelId] ?? null,
        );
    }

    #[\Override]
    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * @return array{
     *     role: ?CommunityRole,
     *     channelRoles: array<int, ChannelRole>,
     *     groupRoles: array<int, ChannelRole>,
     * }
     */
    private function load(User $user, Community $community): array
    {
        $key = $user->getId().':'.$community->getId();

        return $this->memo[$key] ??= [
            'role' => $this->communityMembers->findRole($user, $community),
            'channelRoles' => $this->channelMembers->findRolesForUserInCommunity($user, $community),
            'groupRoles' => $this->groupChannelPermissions->findRolesForUserInCommunity($user, $community),
        ];
    }
}
