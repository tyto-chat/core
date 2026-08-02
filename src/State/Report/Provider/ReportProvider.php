<?php

declare(strict_types=1);

namespace App\State\Report\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Report;
use App\Service\Report\ReportServiceInterface;

/**
 * @implements ProviderInterface<Report>
 */
final readonly class ReportProvider implements ProviderInterface
{
    public function __construct(
        private ReportServiceInterface $reportService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Report
    {
        return $this->reportService->get((int) $uriVariables['id']);
    }
}
