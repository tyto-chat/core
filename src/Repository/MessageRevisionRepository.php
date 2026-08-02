<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MessageRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessageRevision>
 */
class MessageRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageRevision::class);
    }
}
