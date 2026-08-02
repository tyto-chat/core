<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Authentication\ApiKeyAuthenticator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiKeyScopeGuard
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function insufficientScopeException(string $scope): HttpException
    {
        return new HttpException(
            403,
            sprintf('API key is missing the required scope: %s.', $scope),
            null,
            ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope" scope="%s"', $scope)],
        );
    }

    public function requireScope(string $scope): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $granted = $request?->attributes->get(ApiKeyAuthenticator::REQUEST_SCOPES_ATTRIBUTE);
        // Missing scopes attribute = JWT caller — must pass, not deny.
        if (!is_array($granted)) {
            return;
        }

        if (!in_array($scope, $granted, true)) {
            throw self::insufficientScopeException($scope);
        }
    }
}
