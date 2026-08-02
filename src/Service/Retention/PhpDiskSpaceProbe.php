<?php

declare(strict_types=1);

namespace App\Service\Retention;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PhpDiskSpaceProbe implements DiskSpaceProbeInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/media')] private readonly string $mediaDir,
    ) {
    }

    public function totalBytes(): int
    {
        return (int) @disk_total_space($this->mediaDir);
    }

    public function freeBytes(): int
    {
        return (int) @disk_free_space($this->mediaDir);
    }
}
