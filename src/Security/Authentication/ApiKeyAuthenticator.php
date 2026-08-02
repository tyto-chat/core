<?php

declare(strict_types=1);

namespace App\Security\Authentication;

use App\Service\ApiKey\ApiKeyServiceInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const string TOKEN_HEADER_PREFIX = 'Bearer pat_';
    public const string REQUEST_SCOPES_ATTRIBUTE = '_api_key_scopes';
    public const string REQUEST_KEY_ATTRIBUTE = '_api_key';

    public function __construct(
        private readonly ApiKeyServiceInterface $apiKeyService,
    ) {
    }

    #[\Override]
    public function supports(Request $request): bool
    {
        $header = $request->headers->get('Authorization', '');

        return is_string($header) && str_starts_with($header, self::TOKEN_HEADER_PREFIX);
    }

    #[\Override]
    public function authenticate(Request $request): Passport
    {
        $header = (string) $request->headers->get('Authorization', '');
        $plainToken = substr($header, strlen('Bearer '));

        // One opaque failure — unknown vs revoked vs expired must not be distinguishable externally.
        $key = $this->apiKeyService->findByPlainToken($plainToken);
        if (null === $key || $key->isRevoked() || $key->isExpired()) {
            throw new CustomUserMessageAuthenticationException('Invalid API key.');
        }

        $user = $key->getUser();

        $request->attributes->set(self::REQUEST_SCOPES_ATTRIBUTE, $key->getScopes());
        $request->attributes->set(self::REQUEST_KEY_ATTRIBUTE, $key);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), static fn () => $user),
        );
    }

    #[\Override]
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $key = $request->attributes->get(self::REQUEST_KEY_ATTRIBUTE);
        if ($key instanceof \App\Entity\ApiKey) {
            $this->apiKeyService->touchLastUsed($key);
        }

        return null;
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['error' => 'invalid_token', 'detail' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
            [
                'WWW-Authenticate' => 'Bearer error="invalid_token"',
            ],
        );
    }
}
