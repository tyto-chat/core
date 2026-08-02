<?php

declare(strict_types=1);

namespace App\Service\Retention;

interface DiskPressurePurgeServiceInterface
{
    /**
     * @return array{files: int, bytes: int, freePercent: float, reachedTarget: bool}|null
     *                                                                                     null = valve off, disk unmeasurable, or free space above the trigger
     */
    public function purge(): ?array;
}
