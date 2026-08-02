<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\PurgeArchivedChannelsMessage;
use App\Service\Channel\ChannelServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeArchivedChannelsHandler
{
    public function __construct(
        private readonly ChannelServiceInterface $channelService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeArchivedChannelsMessage $message): void
    {
        $purged = $this->channelService->purgeExpiredArchived();
        if ($purged > 0) {
            $this->logger->info(sprintf('Purged %d expired archived channel(s).', $purged));
        }
    }
}
