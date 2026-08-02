<?php

declare(strict_types=1);

namespace App\Dto\Search;

final readonly class SearchOptions
{
    public function __construct(
        public ?int $authorId = null,
        public ?int $createdBefore = null,
        public ?int $createdAfter = null,
        public int $limit = 25,
        public int $offset = 0,
    ) {
    }
}
