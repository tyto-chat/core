<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DataExportRequest;
use App\Entity\User;
use App\Service\Gdpr\DataExportServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DataExportController extends AbstractController
{
    public function __construct(
        private readonly DataExportServiceInterface $dataExportService,
        private readonly Security $security,
    ) {
    }

    #[Route('/api/{version}/me/data-export', name: 'api_me_data_export_status', methods: ['GET'], requirements: ['version' => '%app.api_version_requirement%'], defaults: ['version' => '%app.api_version_canonical%'])]
    public function status(): JsonResponse
    {
        $request = $this->dataExportService->findActiveForCurrentUser();
        if (null === $request) {
            return $this->json(['pending' => false], 200);
        }

        return $this->json($this->present($request), 200);
    }

    #[Route('/api/{version}/me/data-export', name: 'api_me_data_export_request', methods: ['POST'], requirements: ['version' => '%app.api_version_requirement%'], defaults: ['version' => '%app.api_version_canonical%'])]
    public function request(): JsonResponse
    {
        $created = $this->dataExportService->request();

        return $this->json($this->present($created), 201);
    }

    // Must stay under /api/* (firewall) — prepareDownload is the only other gate, matching the token to the current user.
    #[Route('/api/{version}/me/data-export/download', name: 'api_me_data_export_download', methods: ['GET'], requirements: ['version' => '%app.api_version_requirement%'], defaults: ['version' => '%app.api_version_canonical%'])]
    public function download(Request $request): BinaryFileResponse
    {
        $token = (string) $request->query->get('token', '');
        $user = $this->security->getUser();
        \assert($user instanceof User);

        $resolved = $this->dataExportService->prepareDownload($user, $token);

        $response = new BinaryFileResponse($resolved['path']);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $resolved['fileName'],
        );
        $response->headers->set('Content-Type', $resolved['mimeType']);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DataExportRequest $request): array
    {
        return [
            'pending' => true,
            'id' => $request->getId(),
            'status' => $request->getStatus(),
            'requestedAt' => $request->getRequestedAt()->format(\DateTimeInterface::ATOM),
            'readyAt' => $request->getReadyAt()?->format(\DateTimeInterface::ATOM),
            'expiresAt' => $request->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'fileSize' => $request->getFileSize(),
            'downloadUrl' => DataExportRequest::STATUS_READY === $request->getStatus() && null !== $request->getDownloadToken()
                ? $this->generateUrl('api_me_data_export_download', ['token' => $request->getDownloadToken()])
                : null,
        ];
    }
}
