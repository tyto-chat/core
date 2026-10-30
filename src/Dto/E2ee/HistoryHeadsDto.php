<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class HistoryHeadsDto
{
    /** @param array<string, int>|\stdClass $heads */
    public function __construct(
        #[Groups(['e2ee:read'])]
        public array|\stdClass $heads,
    ) {
    }
}
