<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

interface UserContextServiceInterface
{
    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function runAs(User $user, callable $fn): mixed;
}
