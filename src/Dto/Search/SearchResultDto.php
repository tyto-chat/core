<?php

declare(strict_types=1);

namespace App\Dto\Search;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class SearchResultDto
{
    /** @param SearchHitDto[] $hits */
    public function __construct(
        #[Groups(['search:read'])]
        public array $hits = [],
        #[Groups(['search:read'])]
        public int $total = 0,
        #[Groups(['search:read'])]
        public int $limit = 0,
        #[Groups(['search:read'])]
        public int $offset = 0,
    ) {
    }
}
