<?php

declare(strict_types=1);

namespace App\Service\ApiKey;

use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Enum\User\UserRole;
use App\Exception\ApiKey\InvalidScopeException;

class ScopeIssuancePolicy implements ScopeIssuancePolicyInterface
{
    /** @return list<string> */
    #[\Override]
    public function allowedScopesFor(User $user): array
    {
        $roles = $user->getRoles();
        $isAdmin = in_array(UserRole::Admin->value, $roles, true);

        $allowed = [];
        foreach (ApiKeyScope::cases() as $scope) {
            if (ApiKeyScope::Admin === $scope && !$isAdmin) {
                continue;
            }
            $allowed[] = $scope->value;
        }

        return $allowed;
    }

    /** @param list<string> $scopes */
    #[\Override]
    public function assertAllowed(User $user, array $scopes): void
    {
        $allowed = $this->allowedScopesFor($user);
        foreach ($scopes as $scope) {
            if (!in_array($scope, $allowed, true)) {
                throw new InvalidScopeException(sprintf('Scope "%s" is not available to this user.', $scope));
            }
        }
    }
}
