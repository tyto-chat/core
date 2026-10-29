<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeGroupEpoch;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class CommitResultDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $commitId,
        #[Groups(['e2ee:read'])]
        public int $epoch,
        #[Groups(['e2ee:read'])]
        public int $generation,
    ) {
    }

    public static function fromEpoch(E2eeGroupEpoch $epoch): self
    {
        return new self($epoch->getCommitId(), $epoch->getEpoch(), $epoch->getGeneration());
    }
}
