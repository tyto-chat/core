<?php

declare(strict_types=1);

namespace App\E2ee;

final class HistoryRecordPayload
{
    public static function encode(string $deviceId, int $generation, int $counter, string $blob): string
    {
        return CanonicalCbor::encodeMap([
            'deviceId' => $deviceId,
            'generation' => $generation,
            'counter' => $counter,
            'blob' => new ByteString(hash('sha256', $blob, true)),
        ]);
    }
}
