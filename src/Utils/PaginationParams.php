<?php

declare(strict_types=1);

namespace App\Utils;

use Symfony\Component\HttpFoundation\Request;

final readonly class PaginationParams
{
    private function __construct(
        public int $page,
        public int $perPage,
    ) {
    }

    public static function fromRequest(?Request $request, int $defaultPerPage = 25, int $maxPerPage = 100): self
    {
        return new self(
            max(1, (int) ($request?->query->get('page') ?? 1)),
            min($maxPerPage, max(1, (int) ($request?->query->get('perPage') ?? $defaultPerPage))),
        );
    }
}
