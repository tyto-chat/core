<?php

declare(strict_types=1);

namespace App\Service\Report;

use App\Dto\Report\CreateReportDto;
use App\Dto\Report\UpdateReportDto;
use App\Entity\Community;
use App\Entity\Report;
use App\Enum\Moderation\ReportStatus;

interface ReportServiceInterface
{
    public function create(CreateReportDto $dto): Report;

    /** @internal No authz — caller must gate. */
    public function get(int $id): Report;

    /** @return list<Report> */
    public function listForCommunity(Community $community, ?ReportStatus $status = null, int $page = 1): array;

    /** @return list<Report> */
    public function listForAdmin(?ReportStatus $status = null, int $page = 1): array;

    public function update(Report $report, UpdateReportDto $dto): Report;
}
