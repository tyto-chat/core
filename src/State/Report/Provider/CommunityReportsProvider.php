<?php

declare(strict_types=1);

namespace App\State\Report\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Report;
use App\Enum\Moderation\ReportStatus;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Report\ReportServiceInterface;

/**
 * @implements ProviderInterface<Report>
 */
final readonly class CommunityReportsProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ReportServiceInterface $reportService,
    ) {
    }

    /** @return list<Report> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $request = $context['request'] ?? null;
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $status = ReportStatus::tryFrom((string) ($request?->query->get('status') ?? ''));
        $page = (int) ($request?->query->get('page') ?? 1);

        return $this->reportService->listForCommunity($community, $status, $page);
    }
}
