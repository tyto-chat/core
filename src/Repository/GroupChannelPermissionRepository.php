<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\GroupChannelPermission;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Enum\Channel\ChannelRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupChannelPermission>
 */
class GroupChannelPermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupChannelPermission::class);
    }

    public function findOneByGroupAndChannel(UserGroup $group, Channel $channel): ?GroupChannelPermission
    {
        return $this->findOneBy(['userGroup' => $group, 'channel' => $channel]);
    }

    /** @return GroupChannelPermission[] */
    public function findByGroup(UserGroup $group): array
    {
        return $this->findBy(['userGroup' => $group]);
    }

    public function findHighestRoleForUserInChannel(User $user, Channel $channel): ?ChannelRole
    {
        $roles = $this->createQueryBuilder('p')
            ->select('p.role')
            ->join('p.userGroup', 'g')
            ->join('g.members', 'm')
            ->where('m.user = :user')
            ->andWhere('p.channel = :channel')
            ->setParameter('user', $user)
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $roles) {
            return null;
        }

        // getSingleColumnResult() bypasses the enumType mapping — rows are raw strings, not ChannelRole.
        return in_array(ChannelRole::Moderator->value, $roles, true)
            ? ChannelRole::Moderator
            : ChannelRole::Member;
    }

    /**
     * @return array<int, ChannelRole> channel id → highest derived role
     */
    public function findRolesForUserInCommunity(User $user, Community $community): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.channel) AS channelId', 'p.role AS role')
            ->join('p.userGroup', 'g')
            ->join('g.members', 'm')
            ->join('p.channel', 'c')
            ->where('m.user = :user')
            ->andWhere('c.community = :community')
            ->setParameter('user', $user)
            ->setParameter('community', $community)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $channelId = (int) $row['channelId'];
            $role = $row['role'] instanceof ChannelRole ? $row['role'] : ChannelRole::from((string) $row['role']);
            if (ChannelRole::Moderator === ($result[$channelId] ?? null)) {
                continue;
            }
            $result[$channelId] = $role;
        }

        return $result;
    }
}
