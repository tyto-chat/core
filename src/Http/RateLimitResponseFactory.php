<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RateLimitResponseFactory
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function tooManyRequests(RateLimit $rateLimit): JsonResponse
    {
        $waitSeconds = max(0, $rateLimit->getRetryAfter()->getTimestamp() - time());

        return new JsonResponse(
            ['error' => $this->translator->trans('rate_limit.too_many_requests', [], 'messages')],
            Response::HTTP_TOO_MANY_REQUESTS,
            [
                'Retry-After' => (string) $waitSeconds,
                'X-RateLimit-Limit' => (string) $rateLimit->getLimit(),
                'X-RateLimit-Remaining' => (string) $rateLimit->getRemainingTokens(),
            ],
        );
    }
}
