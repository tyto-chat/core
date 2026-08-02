<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\MediaObjectRepository;
use App\Service\Gdpr\ExportDownloadLimiterInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\MediaObject\SignedUrlServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class MediaObjectController extends AbstractController
{
    #[Route('/media/{token}/{filter}/{filename}', name: 'app_media_serve_variant', methods: ['GET'])]
    #[Route('/media/{token}/{filename}', name: 'app_media_serve', methods: ['GET'], defaults: ['filter' => null])]
    public function serve(
        string $token,
        string $filename,
        ?string $filter,
        MediaObjectServiceInterface $mediaObjectService,
        SignedUrlServiceInterface $signedUrlService,
        #[Autowire('%kernel.project_dir%/var/media')] string $mediaDir,
    ): BinaryFileResponse {
        $relativePath = null !== $filter ? $filter.'/'.$filename : $filename;

        if (!$signedUrlService->verify($relativePath, $token)) {
            throw new NotFoundHttpException();
        }

        $mediaObject = $mediaObjectService->getByFilePath($filename);

        $path = null !== $filter
            ? $mediaDir.'/'.$filter.'/'.$filename
            : $mediaDir.'/'.$filename;

        if (!is_file($path)) {
            // Missing file on disk must 404, not let BinaryFileResponse 500.
            throw new NotFoundHttpException();
        }

        $response = new BinaryFileResponse($path);

        if ('community_emoji' === $mediaObject->type) {
            $expiry = (int) explode('.', $token, 2)[1];
            $maxAge = max(0, $expiry - time());
            $response->setPublic();
            $response->setMaxAge($maxAge);
            $response->headers->set('Cache-Control', $response->headers->get('Cache-Control').', immutable');
        }

        return $response;
    }

    // Kept separate from /media/: export URLs get a per-token download cap; capping the regular media path would 429 falsely.
    #[Route('/export-media/{token}/{filename}', name: 'app_media_serve_export', methods: ['GET'])]
    public function serveExport(
        string $token,
        string $filename,
        MediaObjectRepository $mediaObjectRepository,
        SignedUrlServiceInterface $signedUrlService,
        ExportDownloadLimiterInterface $limiter,
        LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/media')] string $mediaDir,
    ): Response {
        if (!$signedUrlService->verify($filename, $token)) {
            // 410, not 404 — the resource may have existed; the token no longer authorises it.
            return new JsonResponse(['error' => 'Link expired or invalid.'], Response::HTTP_GONE);
        }

        $expiry = (int) explode('.', $token, 2)[1];
        $ttlRemaining = max(1, $expiry - time());

        if (!$limiter->incrementAndCheck($token, $ttlRemaining)) {
            $logger->warning('gdpr.export.attachment_dl_capped', [
                'channel' => 'gdpr',
                'token_sha' => substr(hash('sha256', $token), 0, 12),
            ]);

            return new JsonResponse(
                ['error' => 'Download limit reached for this attachment. Re-export to refresh URLs.'],
                Response::HTTP_TOO_MANY_REQUESTS,
            );
        }

        // Repository, not getByFilePath (voter) — the signed URL is the sole authz; the caller may be anonymous.
        $mediaObject = $mediaObjectRepository->findByFilePath($filename);
        if (null === $mediaObject || 'attachment' !== $mediaObject->type) {
            throw new NotFoundHttpException();
        }

        $path = $mediaDir.'/'.$filename;
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        return new BinaryFileResponse($path);
    }
}
