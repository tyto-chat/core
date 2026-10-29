<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeGroupEpoch;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class CommitDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $commitId,
        #[Groups(['e2ee:read'])]
        public int $epoch,
        #[Groups(['e2ee:read'])]
        public int $generation,
        #[Groups(['e2ee:read'])]
        public string $deviceId,
        #[Groups(['e2ee:read'])]
        public string $commit,
        #[Groups(['e2ee:read'])]
        public ?string $leaveRequestId,
        #[Groups(['e2ee:read'])]
        public int $createdAt,
    ) {
    }

    public static function fromEpoch(E2eeGroupEpoch $epoch): self
    {
        return new self(
            $epoch->getCommitId(),
            $epoch->getEpoch(),
            $epoch->getGeneration(),
            $epoch->getDeviceId(),
            $epoch->getCommit(),
            $epoch->getLeaveRequestId(),
            $epoch->getCreatedAt()->getTimestamp(),
        );
    }
}
