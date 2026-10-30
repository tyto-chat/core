<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeHistoryRecord;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class HistoryRecordDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $deviceId,
        #[Groups(['e2ee:read'])]
        public int $counter,
        #[Groups(['e2ee:read'])]
        public string $blob,
    ) {
    }

    public static function fromRecord(E2eeHistoryRecord $record): self
    {
        return new self($record->getDevice()->getId(), $record->getCounter(), $record->getBlob());
    }
}
