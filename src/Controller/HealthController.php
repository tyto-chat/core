<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\Health\HealthStatus;
use App\Health\HealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class HealthController extends AbstractController
{
    private const CACHE_KEY = 'health.public.down';
    private const CACHE_TTL_SECONDS = 10;

    public function __construct(
        private readonly HealthService $healthService,
        private readonly CacheInterface $cache,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function liveness(): JsonResponse
    {
        try {
            // Cache is load-bearing: anonymous + rate-limit-exempt endpoint; uncached hits fan out to every probe (amplification vector).
            $down = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): bool {
                $item->expiresAfter(self::CACHE_TTL_SECONDS);

                $results = $this->healthService->runAll();

                return HealthStatus::Down === $this->healthService->overall($results);
            });
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'down'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(
            ['status' => $down ? 'down' : 'ok'],
            $down ? Response::HTTP_SERVICE_UNAVAILABLE : Response::HTTP_OK,
        );
    }
}
