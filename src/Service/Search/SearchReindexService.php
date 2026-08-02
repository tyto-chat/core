<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\AbstractDoctrineService;
use App\Service\Message\MessageServiceInterface;

class SearchReindexService extends AbstractDoctrineService implements SearchReindexServiceInterface
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly MessageServiceInterface $messageService,
        private readonly SearchServiceInterface $searchService,
    ) {
    }

    #[\Override]
    public function countIndexable(): int
    {
        return $this->messageService->countIndexableMessages();
    }

    #[\Override]
    public function reindex(?callable $onBatch = null): int
    {
        $this->searchService->ensureIndexConfigured();

        $offset = 0;
        $indexed = 0;
        while (true) {
            $batch = $this->messageService->findIndexableMessagesBatch($offset, self::BATCH_SIZE);
            if ([] === $batch) {
                break;
            }

            $this->searchService->indexMessages($batch);
            $indexed += count($batch);
            if (null !== $onBatch) {
                $onBatch(count($batch));
            }

            $this->clear();
            $offset += self::BATCH_SIZE;
        }

        return $indexed;
    }
}
