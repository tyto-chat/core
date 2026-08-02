<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Community;
use App\Entity\CommunityEmoji;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommunityEmoji>
 */
class CommunityEmojiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunityEmoji::class);
    }

    /** @return CommunityEmoji[] */
    public function findByCommunity(Community $community): array
    {
        return $this->findBy(['community' => $community], ['position' => 'ASC', 'id' => 'ASC']);
    }

    public function findMaxPosition(Community $community): ?int
    {
        $value = $this->createQueryBuilder('e')
            ->select('MAX(e.position)')
            ->where('e.community = :community')
            ->setParameter('community', $community)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $value ? null : (int) $value;
    }

    public function findByCommunityAndShortcode(Community $community, string $shortcode): ?CommunityEmoji
    {
        return $this->findOneBy([
            'community' => $community,
            'shortcode' => $shortcode,
        ]);
    }
}
