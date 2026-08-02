<?php

declare(strict_types=1);

namespace App\Dto\Stats;

use Symfony\Component\Serializer\Attribute\Groups;

class SystemInfoDto
{
    public function __construct(
        #[Groups(['stats:read'])]
        public readonly int $cpuCount,
        #[Groups(['stats:read'])]
        public readonly float $cpuLoad1,
        #[Groups(['stats:read'])]
        public readonly float $cpuLoad5,
        #[Groups(['stats:read'])]
        public readonly float $cpuLoad15,
        #[Groups(['stats:read'])]
        public readonly int $memoryTotal,
        #[Groups(['stats:read'])]
        public readonly int $memoryUsed,
        #[Groups(['stats:read'])]
        public readonly int $memoryFree,
        #[Groups(['stats:read'])]
        public readonly int $diskTotal,
        #[Groups(['stats:read'])]
        public readonly int $diskFree,
        #[Groups(['stats:read'])]
        public readonly int $mediaSize,
    ) {
    }
}
