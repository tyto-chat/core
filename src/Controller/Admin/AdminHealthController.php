<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Health\HealthService;
use App\Scheduler\ScheduledTasksProvider;
use App\Security\Attribute\RequiresScope;
use App\Security\Voter\AdminPanelVoter;
use App\Service\ServerInfo\SystemInfoServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/{version}/admin/health', name: 'admin_health_', requirements: ['version' => '%app.api_version_requirement%'], defaults: ['version' => '%app.api_version_canonical%'])]
#[IsGranted(AdminPanelVoter::VIEW)]
#[RequiresScope(scope: 'admin')]
class AdminHealthController extends AbstractController
{
    public function __construct(
        private readonly HealthService $healthService,
        private readonly SystemInfoServiceInterface $systemInfoService,
        private readonly ScheduledTasksProvider $scheduledTasksProvider,
    ) {
    }

    #[Route('', name: 'get', methods: ['GET'])]
    public function detail(): JsonResponse
    {
        $results = $this->healthService->runAll();
        $overall = $this->healthService->overall($results);
        $sys = $this->systemInfoService->getSystemInfo();

        return $this->json([
            'overall' => $overall->value,
            'checks' => array_map(static fn ($r) => $r->toArray(), $results),
            'system' => [
                'cpuCount' => $sys->cpuCount,
                'cpuLoad1' => $sys->cpuLoad1,
                'cpuLoad5' => $sys->cpuLoad5,
                'cpuLoad15' => $sys->cpuLoad15,
                'memoryTotal' => $sys->memoryTotal,
                'memoryUsed' => $sys->memoryUsed,
                'memoryFree' => $sys->memoryFree,
                'diskTotal' => $sys->diskTotal,
                'diskFree' => $sys->diskFree,
                'mediaSize' => $sys->mediaSize,
            ],
            'scheduledTasks' => $this->scheduledTasksProvider->list(),
        ]);
    }
}
