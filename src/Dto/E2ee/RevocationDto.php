<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeDevice;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class RevocationDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $deviceId,
        #[Groups(['e2ee:read'])]
        public int $generation,
        #[Groups(['e2ee:read'])]
        public int $revokedAt,
        #[Groups(['e2ee:read'])]
        public string $signerDeviceId,
        #[Groups(['e2ee:read'])]
        public string $signature,
        #[Groups(['e2ee:read'])]
        public string $signerPublicKey,
        #[Groups(['e2ee:read'])]
        public string $signerCredential,
        #[Groups(['e2ee:read'])]
        public string $signerServer,
        #[Groups(['e2ee:read'])]
        public ?string $leaveRequestId,
    ) {
    }

    /** @param array{device: E2eeDevice, signer: E2eeDevice} $row */
    public static function fromRow(array $row): self
    {
        $revocation = $row['device']->getRevocation() ?? throw new \LogicException('Device is not revoked.');

        return new self(
            $row['device']->getId(),
            $row['device']->getGeneration(),
            $revocation['revokedAt'],
            $revocation['signerDeviceId'],
            $revocation['signature'],
            $row['signer']->getPublicKey(),
            $row['signer']->getCredential(),
            $row['signer']->getServer(),
            $revocation['leaveRequestId'] ?? null,
        );
    }
}
