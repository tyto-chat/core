<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\PurgeRetentionMessage;
use App\Service\Retention\RetentionServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeRetentionHandler
{
    public function __construct(
        private readonly RetentionServiceInterface $retentionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeRetentionMessage $message): void
    {
        $result = $this->retentionService->purge();
        $this->logger->info(sprintf(
            'Retention purge: %d messages redacted, %d notifications deleted.',
            $result['messages'] ?? 0,
            $result['notifications'] ?? 0,
        ));
    }
}
