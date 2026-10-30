<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\Entity\E2eeHistoryRecord;

interface RecoveryBackupServiceInterface
{
    public const int MAX_HISTORY_PAGE = 100;

    public function storeIdentityBackup(string $blob): void;

    /** @return array{blob: string, generation: int} */
    public function getIdentityBackup(): array;

    /**
     * $signature is the device key's Ed25519 signature over HistoryRecordPayload.
     *
     * @return bool true when the record is new, false for an identical resend
     */
    public function appendHistoryRecord(string $deviceId, int $counter, string $blob, string $signature): bool;

    /** @return list<E2eeHistoryRecord> ordered by device id, then counter */
    public function listHistory(?string $afterDeviceId, int $afterCounter, int $limit): array;

    /** @return array<string, int> */
    public function historyHeads(): array;

    /** Revokes every device, replaces the account key and identity backup, clears the history; returns the new generation. Refused unless the account is still at $expectedGeneration. */
    public function reset(string $newPublicKey, string $identityBackup, int $expectedGeneration): int;
}
