<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Attribute\RequiresScope;
use App\Security\Voter\AdminPanelVoter;
use App\Service\Admin\SetupStatusServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// Must not move onto anonymous-readable /api/server-info — leaks which server config is incomplete.
#[Route('/api/{version}/admin/setup-status', name: 'admin_setup_status_', requirements: ['version' => '%app.api_version_requirement%'], defaults: ['version' => '%app.api_version_canonical%'])]
#[IsGranted(AdminPanelVoter::VIEW)]
#[RequiresScope(scope: 'admin')]
class SetupStatusController extends AbstractController
{
    public function __construct(
        private readonly SetupStatusServiceInterface $setupStatusService,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return $this->json($this->setupStatusService->status()->toArray());
    }
}
