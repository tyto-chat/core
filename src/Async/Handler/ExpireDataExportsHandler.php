<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\ExpireDataExportsMessage;
use App\Service\Gdpr\DataExportServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ExpireDataExportsHandler
{
    public function __construct(
        private readonly DataExportServiceInterface $dataExportService,
    ) {
    }

    public function __invoke(ExpireDataExportsMessage $message): void
    {
        $this->dataExportService->expireOld();
    }
}
