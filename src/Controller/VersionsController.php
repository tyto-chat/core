<?php

declare(strict_types=1);

namespace App\Controller;

use App\Utils\ApiVersions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

// Frozen contract for clients of any age: "features" keys may be added, never removed/renamed; deliberately outside /api/{version}.
class VersionsController extends AbstractController
{
    #[Route('/api/versions', name: 'api_versions', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse([
            'versions' => ApiVersions::VERSIONS,
            'features' => ApiVersions::FEATURES,
        ]);
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
