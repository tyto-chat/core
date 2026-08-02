<?php

declare(strict_types=1);

namespace App\Dto\Admin;

/** One setup-completion check. fixable is 'ui' (admin resolves in-panel) or 'infra' (operator resolves via env). */
final readonly class SetupItemDto
{
    public function __construct(
        public string $key,
        public bool $satisfied,
        public string $fixable,
        public ?string $deepLink = null,
    ) {
    }

    /** @return array{key: string, satisfied: bool, fixable: string, deepLink: string|null} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'satisfied' => $this->satisfied, 'fixable' => $this->fixable, 'deepLink' => $this->deepLink];
    }
}
