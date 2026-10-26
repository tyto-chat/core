<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use Symfony\Contracts\Service\ResetInterface;

final class CacheTagCollector implements ResetInterface
{
    /** @var array<string, true> */
    private array $tags = [];

    public function add(string $tag): void
    {
        $this->tags[$tag] = true;
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->tags);
    }

    #[\Override]
    public function reset(): void
    {
        $this->tags = [];
    }
}
