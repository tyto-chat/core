<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use App\Entity\User;

interface CacheContextServiceInterface
{
    public function bucketFor(?User $user, string $uri): string;
}
