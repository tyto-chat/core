<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\HttpCache\CacheContextServiceInterface;
use App\Service\HttpCache\HttpCacheQuarantineInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use App\Utils\CacheTags;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HttpCacheController extends AbstractController
{
    public function __construct(
        private readonly CacheContextServiceInterface $cacheContext,
        private readonly LoggerInterface $logger,
        private readonly SettingsServiceInterface $settings,
        private readonly HttpCacheQuarantineInterface $quarantine,
    ) {
    }

    #[Route('/api/http-cache/context-hash', name: 'app_http_cache_context_hash', methods: ['GET'])]
    public function contextHash(Request $request): Response
    {
        $user = $this->getUser();
        $uri = $request->headers->get('X-Forwarded-Uri', '');
        $bucket = $this->cacheContext->bucketFor($user instanceof User ? $user : null, $uri);

        $this->logger->debug('http_cache.bucket', [
            'channel' => 'http_cache',
            'uri' => $uri,
            'user_id' => $user instanceof User ? $user->getId() : null,
            'bucket' => $bucket,
        ]);

        $response = new Response(null, Response::HTTP_NO_CONTENT);
        $response->headers->set('X-User-Context-Hash', $bucket);

        $ttl = $this->settings->get(Settings::httpCacheContextTtlSeconds());
        $communityId = $this->cacheContext->communityIdFor($uri);
        if (!$request->headers->has('Authorization') && $this->carriesBearerCookie($request)) {
            $ttl = 0;
        }
        if (null !== $communityId && $this->quarantine->isCommunityQuarantined($communityId)) {
            $ttl = 0;
        }
        if ($user instanceof User && $this->quarantine->isUserQuarantined((int) $user->getId())) {
            $ttl = 0;
        }
        $authorization = $request->headers->get('Authorization');
        if (null !== $authorization) {
            $tokenLifetime = $this->remainingTokenLifetime($authorization);
            $ttl = null === $tokenLifetime ? 0 : min($ttl, $tokenLifetime);
        }
        if ($ttl <= 0) {
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $tags = [];
        if ($user instanceof User) {
            $tags[] = CacheTags::userContext((int) $user->getId());
        }
        if (null !== $communityId) {
            $tags[] = CacheTags::communityContext($communityId);
        }

        $response->setPublic();
        $response->headers->addCacheControlDirective('s-maxage', (string) $ttl);
        $response->setVary(['Authorization', 'X-Forwarded-Uri'], false);
        if ([] !== $tags) {
            $response->headers->set('Surrogate-Key', implode(', ', $tags));
        }

        return $response;
    }

    private function remainingTokenLifetime(string $authorization): ?int
    {
        if (1 !== preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m)) {
            return null;
        }

        $segments = explode('.', $m[1]);
        if (3 !== \count($segments)) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/'), true), true);
        $exp = \is_array($payload) ? ($payload['exp'] ?? null) : null;

        return \is_int($exp) ? $exp - time() : null;
    }

    private function carriesBearerCookie(Request $request): bool
    {
        return $request->cookies->has('BEARER')
            || 1 === preg_match('/(?:^|;\s*)BEARER=/', (string) $request->headers->get('Cookie', ''));
    }
}
