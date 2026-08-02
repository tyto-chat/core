<?php

declare(strict_types=1);

namespace App\State\Report\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Report\UpdateReportDto;
use App\Entity\Report;
use App\Service\Report\ReportServiceInterface;

/**
 * @implements ProcessorInterface<UpdateReportDto, Report>
 */
final readonly class UpdateReportProcessor implements ProcessorInterface
{
    public function __construct(
        private ReportServiceInterface $reportService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Report
    {
        /** @var UpdateReportDto $data */
        $report = $this->reportService->get((int) $uriVariables['id']);

        return $this->reportService->update($report, $data);
    }
}
