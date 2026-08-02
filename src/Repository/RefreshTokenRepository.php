<?php

declare(strict_types=1);

namespace App\Repository;

use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshTokenRepository as BaseRefreshTokenRepository;

class RefreshTokenRepository extends BaseRefreshTokenRepository
{
    public function deleteAllForUsername(string $username): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\RefreshToken rt WHERE rt.username = :username')
            ->setParameter('username', $username)
            ->execute();
    }
}
