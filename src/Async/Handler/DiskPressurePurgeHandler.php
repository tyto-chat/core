<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\DiskPressurePurgeMessage;
use App\Service\Retention\DiskPressurePurgeServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class DiskPressurePurgeHandler
{
    public function __construct(
        private readonly DiskPressurePurgeServiceInterface $diskPressurePurgeService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DiskPressurePurgeMessage $message): void
    {
        $result = $this->diskPressurePurgeService->purge();
        if (null === $result) {
            return;
        }

        $this->logger->info(sprintf(
            'Disk-pressure purge: %d attachments deleted, %d bytes freed, free space %.1f%%.',
            $result['files'],
            $result['bytes'],
            $result['freePercent'],
        ));
    }
}
