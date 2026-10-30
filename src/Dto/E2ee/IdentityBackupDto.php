<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class IdentityBackupDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $blob,
        #[Groups(['e2ee:read'])]
        public int $generation,
    ) {
    }
}
