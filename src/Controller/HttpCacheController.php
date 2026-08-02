<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\HttpCache\CacheContextServiceInterface;
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
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
