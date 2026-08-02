<?php

declare(strict_types=1);

namespace App\State\Report\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Report\CreateReportDto;
use App\Entity\Report;
use App\Service\Report\ReportServiceInterface;

/**
 * @implements ProcessorInterface<CreateReportDto, Report>
 */
final readonly class CreateReportProcessor implements ProcessorInterface
{
    public function __construct(
        private ReportServiceInterface $reportService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Report
    {
        /* @var CreateReportDto $data */
        return $this->reportService->create($data);
    }
}
