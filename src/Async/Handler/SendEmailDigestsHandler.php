<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\SendEmailDigestsMessage;
use App\Service\Notification\EmailDigestServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendEmailDigestsHandler
{
    public function __construct(
        private readonly EmailDigestServiceInterface $digestService,
    ) {
    }

    public function __invoke(SendEmailDigestsMessage $message): void
    {
        $this->digestService->dispatchDue();
    }
}
