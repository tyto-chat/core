<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelMember>
 */
class ChannelMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelMember::class);
    }

    public function isChannelMember(User $user, Channel $channel): bool
    {
        return null !== $this->findOneBy(['user' => $user, 'channel' => $channel]);
    }

    public function findOneByUserAndChannel(User $user, Channel $channel): ?ChannelMember
    {
        return $this->findOneBy(['user' => $user, 'channel' => $channel]);
    }

    /** @return ChannelMember[] */
    public function findByChannel(Channel $channel): array
    {
        return $this->findBy(['channel' => $channel], ['addedAt' => 'ASC']);
    }

    /**
     * @return array<int, ChannelRole> channel id → role
     */
    public function findRolesForUserInCommunity(User $user, Community $community): array
    {
        $rows = $this->createQueryBuilder('cm')
            ->select('IDENTITY(cm.channel) AS channelId', 'cm.role AS role')
            ->join('cm.channel', 'ch')
            ->where('cm.user = :user')
            ->andWhere('ch.community = :community')
            ->setParameter('user', $user)
            ->setParameter('community', $community)
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $role = $row['role'] instanceof ChannelRole ? $row['role'] : ChannelRole::from((string) $row['role']);
            $result[(int) $row['channelId']] = $role;
        }

        return $result;
    }

    public function findModeratorMembershipInCommunity(User $user, Community $community): ?ChannelMember
    {
        return $this->createQueryBuilder('cm')
            ->join('cm.channel', 'ch')
            ->where('cm.user = :user')
            ->andWhere('cm.role = :role')
            ->andWhere('ch.community = :community')
            ->setParameter('user', $user)
            ->setParameter('role', ChannelRole::Moderator->value)
            ->setParameter('community', $community)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteForUserInCommunity(User $user, Community $community): int
    {
        return (int) $this->getEntityManager()
            ->createQuery(
                'DELETE FROM App\Entity\ChannelMember cm
                 WHERE cm.user = :user
                 AND cm.channel IN (SELECT c.id FROM App\Entity\Channel c WHERE c.community = :community)'
            )
            ->setParameter('user', $user)
            ->setParameter('community', $community)
            ->execute();
    }
}
