<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\ModerationAction;
use App\Entity\User;
use App\Enum\Moderation\ModerationActionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModerationAction>
 */
class ModerationActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationAction::class);
    }

    public function findActiveTimeoutInScope(Community $community, User $user, ?Channel $channel = null): ?ModerationAction
    {
        $qb = $this->createQueryBuilder('ma')
            ->where('ma.community = :community')
            ->andWhere('ma.targetUser = :user')
            ->andWhere('ma.type = :type')
            ->setParameter('community', $community)
            ->setParameter('user', $user)
            ->setParameter('type', ModerationActionType::Timeout->value)
            ->orderBy('ma.createdAt', 'DESC')
            ->setMaxResults(1);
        $this->andActive($qb, requireExpiry: true);

        if (null === $channel) {
            $qb->andWhere('ma.channel IS NULL');
        } else {
            $qb->andWhere('ma.channel = :channel')
               ->setParameter('channel', $channel);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findActiveBanByUserAndCommunity(Community $community, User $user): ?ModerationAction
    {
        $qb = $this->createQueryBuilder('ma')
            ->where('ma.community = :community')
            ->andWhere('ma.targetUser = :user')
            ->andWhere('ma.type = :type')
            ->setParameter('community', $community)
            ->setParameter('user', $user)
            ->setParameter('type', ModerationActionType::Ban->value)
            ->orderBy('ma.createdAt', 'DESC')
            ->setMaxResults(1);
        $this->andActive($qb);

        return $qb->getQuery()->getOneOrNullResult();
    }

    public function findActiveServerBan(User $user): ?ModerationAction
    {
        // Server bans are app-wide — the non-null community FK only records where the ban was issued; never filter by it.
        $qb = $this->createQueryBuilder('ma')
            ->where('ma.targetUser = :user')
            ->andWhere('ma.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', ModerationActionType::ServerBan->value)
            ->orderBy('ma.createdAt', 'DESC')
            ->setMaxResults(1);
        $this->andActive($qb);

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return ModerationAction[]
     */
    public function findActiveByUserAndCommunity(Community $community, User $user): array
    {
        $qb = $this->createQueryBuilder('ma')
            ->where('ma.community = :community')
            ->andWhere('ma.targetUser = :user')
            ->andWhere('ma.type != :warn')
            ->setParameter('community', $community)
            ->setParameter('user', $user)
            ->setParameter('warn', ModerationActionType::Warn->value)
            ->orderBy('ma.createdAt', 'DESC');
        $this->andActive($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return ModerationAction[]
     */
    public function findLogByCommunity(
        Community $community,
        int $page = 1,
        int $perPage = 50,
        ?Channel $channelFilter = null,
        ?string $typeFilter = null,
        bool $activeOnly = false,
        ?User $targetUser = null,
    ): array {
        $qb = $this->createQueryBuilder('ma')
            ->where('ma.community = :community')
            ->setParameter('community', $community)
            ->orderBy('ma.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->applyLogFilters($qb, $channelFilter, $typeFilter, $activeOnly, $targetUser);

        return $qb->getQuery()->getResult();
    }

    public function countLogByCommunity(
        Community $community,
        ?Channel $channelFilter = null,
        ?string $typeFilter = null,
        bool $activeOnly = false,
        ?User $targetUser = null,
    ): int {
        $qb = $this->createQueryBuilder('ma')
            ->select('COUNT(ma.id)')
            ->where('ma.community = :community')
            ->setParameter('community', $community);
        $this->applyLogFilters($qb, $channelFilter, $typeFilter, $activeOnly, $targetUser);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countBotTimeoutsByUserAndCommunity(Community $community, User $user, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('ma')
            ->select('COUNT(ma.id)')
            ->where('ma.community = :community')
            ->andWhere('ma.targetUser = :user')
            ->andWhere('ma.type = :type')
            ->andWhere('ma.createdAt >= :since')
            ->join('ma.actorUser', 'actor')
            ->andWhere('actor.isBot = true')
            ->setParameter('community', $community)
            ->setParameter('user', $user)
            ->setParameter('type', ModerationActionType::Timeout->value)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return ModerationAction[]
     */
    public function findActiveTimeoutsByUserAndCommunity(Community $community, User $user): array
    {
        $qb = $this->createQueryBuilder('ma')
            ->where('ma.community = :community')
            ->andWhere('ma.targetUser = :user')
            ->andWhere('ma.type = :type')
            ->setParameter('community', $community)
            ->setParameter('user', $user)
            ->setParameter('type', ModerationActionType::Timeout->value);
        $this->andActive($qb, requireExpiry: true);

        return $qb->getQuery()->getResult();
    }

    private function andActive(QueryBuilder $qb, bool $requireExpiry = false): void
    {
        $qb->andWhere('ma.liftedAt IS NULL')
            ->andWhere($requireExpiry ? 'ma.expiresAt > :now' : '(ma.expiresAt IS NULL OR ma.expiresAt > :now)')
            ->setParameter('now', new \DateTimeImmutable());
    }

    private function applyLogFilters(
        QueryBuilder $qb,
        ?Channel $channelFilter,
        ?string $typeFilter,
        bool $activeOnly,
        ?User $targetUser,
    ): void {
        if (null !== $channelFilter) {
            $qb->andWhere('ma.channel = :channel')
               ->setParameter('channel', $channelFilter);
        }

        if (null !== $typeFilter) {
            $qb->andWhere('ma.type = :type')
               ->setParameter('type', $typeFilter);
        }

        if ($activeOnly) {
            $this->andActive($qb);
        }

        if (null !== $targetUser) {
            $qb->andWhere('ma.targetUser = :targetUser')
               ->setParameter('targetUser', $targetUser);
        }
    }
}
