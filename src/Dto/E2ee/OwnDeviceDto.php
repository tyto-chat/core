<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeDevice;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class OwnDeviceDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $id,
        #[Groups(['e2ee:read'])]
        public string $name,
        #[Groups(['e2ee:read'])]
        public string $publicKey,
        #[Groups(['e2ee:read'])]
        public string $credential,
        #[Groups(['e2ee:read'])]
        public string $server,
        #[Groups(['e2ee:read'])]
        public int $generation,
        #[Groups(['e2ee:read'])]
        public string $createdAt,
        #[Groups(['e2ee:read'])]
        public string $lastSeenAt,
    ) {
    }

    public static function fromDevice(E2eeDevice $device): self
    {
        return new self(
            $device->getId(),
            $device->getName(),
            $device->getPublicKey(),
            $device->getCredential(),
            $device->getServer(),
            $device->getGeneration(),
            $device->getCreatedAt()->format(\DATE_ATOM),
            $device->getLastSeenAt()->format(\DATE_ATOM),
        );
    }
}
