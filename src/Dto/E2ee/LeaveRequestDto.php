<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeLeaveRequest;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class LeaveRequestDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $leaveRequestId,
        #[Groups(['e2ee:read'])]
        public string $deviceId,
        #[Groups(['e2ee:read'])]
        public int $userId,
        #[Groups(['e2ee:read'])]
        public int $generation,
        #[Groups(['e2ee:read'])]
        public int $requestedAt,
        #[Groups(['e2ee:read'])]
        public string $signature,
        #[Groups(['e2ee:read'])]
        public string $devicePublicKey,
        #[Groups(['e2ee:read'])]
        public string $credential,
        #[Groups(['e2ee:read'])]
        public string $server,
    ) {
    }

    public static function fromRequest(E2eeLeaveRequest $request): self
    {
        $device = $request->getDevice();
        $revocation = $device->getRevocation() ?? throw new \LogicException('Leave request without revocation.');

        return new self(
            $request->getLeaveRequestId(),
            $device->getId(),
            (int) $device->getAccount()->getUser()->getId(),
            $device->getGeneration(),
            $revocation['revokedAt'],
            $revocation['signature'],
            $device->getPublicKey(),
            $device->getCredential(),
            $device->getServer(),
        );
    }
}
