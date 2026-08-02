<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\IndexMessageDocumentMessage;
use App\Repository\MessageRepository;
use App\Service\Search\SearchServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IndexMessageDocumentHandler
{
    public function __construct(
        private MessageRepository $messageRepository,
        private SearchServiceInterface $searchService,
    ) {
    }

    public function __invoke(IndexMessageDocumentMessage $message): void
    {
        $entity = $this->messageRepository->find($message->messageId);
        if (null === $entity) {
            $this->searchService->removeMessage($message->messageId);

            return;
        }

        // indexMessage removes rather than indexes system/deleted messages.
        $this->searchService->indexMessage($entity);
    }
}
