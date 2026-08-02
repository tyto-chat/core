<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\PurgeExpiredAccountsMessage;
use App\Service\Gdpr\AccountDeletionServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeExpiredAccountsHandler
{
    public function __construct(
        private readonly AccountDeletionServiceInterface $accountDeletionService,
    ) {
    }

    public function __invoke(PurgeExpiredAccountsMessage $message): void
    {
        $this->accountDeletionService->purgeExpired();
    }
}
