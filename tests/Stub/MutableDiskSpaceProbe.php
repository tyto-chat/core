<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Service\Retention\DiskSpaceProbeInterface;

final class MutableDiskSpaceProbe implements DiskSpaceProbeInterface
{
    public int $total = 0;

    /** @var list<int> */
    public array $freeSequence = [];

    private int $lastFree = 0;

    public function totalBytes(): int
    {
        return $this->total;
    }

    public function freeBytes(): int
    {
        if ([] !== $this->freeSequence) {
            $this->lastFree = array_shift($this->freeSequence);
        }

        return $this->lastFree;
    }
}
