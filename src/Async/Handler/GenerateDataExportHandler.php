<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\GenerateDataExportMessage;
use App\Repository\DataExportRequestRepository;
use App\Service\Gdpr\DataExportServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateDataExportHandler
{
    public function __construct(
        private readonly DataExportRequestRepository $repository,
        private readonly DataExportServiceInterface $dataExportService,
    ) {
    }

    public function __invoke(GenerateDataExportMessage $message): void
    {
        $request = $this->repository->find($message->requestId);
        if (null === $request) {
            return;
        }

        $this->dataExportService->generate($request);
    }
}
