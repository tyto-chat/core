<?php

declare(strict_types=1);

namespace App\Service\Retention;

interface DiskSpaceProbeInterface
{
    public function totalBytes(): int;

    public function freeBytes(): int;
}
