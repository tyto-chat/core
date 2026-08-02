<?php

declare(strict_types=1);

namespace App\Dto\Admin;

final readonly class SetupStatusDto
{
    /** @param list<SetupItemDto> $items */
    public function __construct(
        public bool $needsAttention,
        public array $items,
    ) {
    }

    /** @return array{needsAttention: bool, items: list<array{key: string, satisfied: bool, fixable: string, deepLink: string|null}>} */
    public function toArray(): array
    {
        return [
            'needsAttention' => $this->needsAttention,
            'items' => array_map(static fn (SetupItemDto $i) => $i->toArray(), $this->items),
        ];
    }
}
