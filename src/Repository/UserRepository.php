<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\User\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    #[\Override]
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * @return User[]
     */
    public function findAdmins(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->andWhere('u.isBot = false')
            ->setParameter('role', '%"'.UserRole::Admin->value.'"%')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return User[]
     */
    public function searchNonBot(?string $search, int $limit): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.profile', 'p')
            ->addSelect('p')
            ->where('u.isBot = false')
            ->setMaxResults($limit);

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.addcslashes(trim($search), '%_\\').'%';
            $qb->andWhere('p.name LIKE :term OR u.email LIKE :term')
                ->setParameter('term', $term);
        }

        /** @var User[] $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @return array{rows: list<User>, total: int}
     */
    public function adminSearch(
        ?string $search,
        ?bool $isAdmin,
        ?bool $isPendingDeletion,
        ?bool $isBot,
        int $page,
        int $perPage,
        string $sortBy = 'id',
        string $sortDir = 'DESC',
    ): array {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin('u.profile', 'p')
            ->addSelect('p');

        if (null !== $search && '' !== trim($search)) {
            $term = '%'.addcslashes(trim($search), '%_\\').'%';
            $qb->andWhere('p.name LIKE :term OR u.email LIKE :term')
                ->setParameter('term', $term);
        }

        if (true === $isAdmin) {
            $qb->andWhere('u.roles LIKE :adminRole')
                ->setParameter('adminRole', '%"'.UserRole::Admin->value.'"%');
        } elseif (false === $isAdmin) {
            $qb->andWhere('u.roles NOT LIKE :adminRole OR u.roles IS NULL')
                ->setParameter('adminRole', '%"'.UserRole::Admin->value.'"%');
        }

        if (true === $isPendingDeletion) {
            $qb->andWhere('u.deletionRequestedAt IS NOT NULL');
        } elseif (false === $isPendingDeletion) {
            $qb->andWhere('u.deletionRequestedAt IS NULL');
        }

        if (true === $isBot) {
            $qb->andWhere('u.isBot = true');
        } elseif (false === $isBot) {
            $qb->andWhere('u.isBot = false');
        }

        $countQb = (clone $qb)->select('COUNT(u.id)')->resetDQLPart('orderBy');
        /** @var int $total */
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $sortColumns = [
            'id' => 'u.id',
            'email' => 'u.email',
            'name' => 'p.name',
            'createdAt' => 'u.createdAt',
        ];
        $dir = 'ASC' === strtoupper($sortDir) ? 'ASC' : 'DESC';
        $qb->orderBy($sortColumns[$sortBy] ?? 'u.id', $dir)
            ->addOrderBy('u.id', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        /** @var list<User> $rows */
        $rows = $qb->getQuery()->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return User[]
     */
    public function findExpiredForPurge(\DateTimeImmutable $cutoff): array
    {
        // isBot=false also excludes already-anonymised rows (anonymise sets isBot) — keeps the purge idempotent.
        return $this->createQueryBuilder('u')
            ->where('u.deletionRequestedAt IS NOT NULL')
            ->andWhere('u.deletionRequestedAt < :cutoff')
            ->andWhere('u.isBot = false')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }
}
