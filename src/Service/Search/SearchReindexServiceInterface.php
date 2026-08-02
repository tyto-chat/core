<?php

declare(strict_types=1);

namespace App\Service\Search;

interface SearchReindexServiceInterface
{
    public function countIndexable(): int;

    /**
     * @param callable(int): void|null $onBatch called with each batch size
     *
     * @return int messages pushed to the index
     */
    public function reindex(?callable $onBatch = null): int;
}
