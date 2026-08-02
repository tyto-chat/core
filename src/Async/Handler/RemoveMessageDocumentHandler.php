<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\RemoveMessageDocumentMessage;
use App\Service\Search\SearchServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RemoveMessageDocumentHandler
{
    public function __construct(private SearchServiceInterface $searchService)
    {
    }

    public function __invoke(RemoveMessageDocumentMessage $message): void
    {
        $this->searchService->removeMessage($message->messageId);
    }
}
