<?php

declare(strict_types=1);

namespace App\Security\Authentication;

use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\HttpFoundation\Request;

// Treats `Bearer pat_*` as "no JWT" so Lexik doesn't claim PAT requests ahead of ApiKeyAuthenticator ("Invalid JWT Token").
final class JwtTokenExtractorDecorator implements TokenExtractorInterface
{
    public const string PAT_PREFIX = 'pat_';

    public function __construct(
        private readonly TokenExtractorInterface $inner,
    ) {
    }

    #[\Override]
    public function extract(Request $request): bool|string
    {
        $token = $this->inner->extract($request);

        if (is_string($token) && str_starts_with($token, self::PAT_PREFIX)) {
            return false;
        }

        return $token;
    }
}
