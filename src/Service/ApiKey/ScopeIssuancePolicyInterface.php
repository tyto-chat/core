<?php

declare(strict_types=1);

namespace App\Service\ApiKey;

use App\Entity\User;

interface ScopeIssuancePolicyInterface
{
    /**
     * @return list<string>
     */
    public function allowedScopesFor(User $user): array;

    /**
     * @param list<string> $scopes
     */
    public function assertAllowed(User $user, array $scopes): void;
}
