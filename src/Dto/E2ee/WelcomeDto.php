<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeWelcome;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class WelcomeDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $id,
        #[Groups(['e2ee:read'])]
        public string $deviceId,
        #[Groups(['e2ee:read'])]
        public string $conversation,
        #[Groups(['e2ee:read'])]
        public int $epoch,
        #[Groups(['e2ee:read'])]
        public int $generation,
        #[Groups(['e2ee:read'])]
        public string $data,
        #[Groups(['e2ee:read'])]
        public int $createdAt,
    ) {
    }

    public static function fromWelcome(E2eeWelcome $welcome): self
    {
        $epoch = $welcome->getGroupEpoch();

        return new self(
            $welcome->getId(),
            $welcome->getDevice()->getId(),
            $epoch->getConversation()->getIdentifier(),
            $epoch->getEpoch(),
            $epoch->getGeneration(),
            $welcome->getData(),
            $welcome->getCreatedAt()->getTimestamp(),
        );
    }
}
