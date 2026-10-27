<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use Symfony\Component\Serializer\Attribute\Groups;

final readonly class UserDevicesDto
{
    /** @param list<PeerDeviceDto> $devices */
    public function __construct(
        #[Groups(['e2ee:read'])]
        public ?string $accountPublicKey,
        #[Groups(['e2ee:read'])]
        public ?int $generation,
        #[Groups(['e2ee:read'])]
        public array $devices,
    ) {
    }
}
