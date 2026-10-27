<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\User;

interface E2eeServiceInterface
{
    public function registerAccount(string $publicKey): E2eeAccount;

    public function registerDevice(string $id, string $name, string $publicKey, string $server, string $credential): E2eeDevice;

    /** @return list<E2eeDevice> */
    public function listOwnDevices(): array;

    /** @return array{account: ?E2eeAccount, devices: list<E2eeDevice>} */
    public function listDevices(int $userId): array;

    /** @return list<array{device: E2eeDevice, signer: E2eeDevice}> revoked devices with the device that signed each revocation */
    public function listRevocations(int $userId): array;

    public function revokeDevice(string $deviceId, string $signerDeviceId, int $revokedAt, string $signature): void;

    public function isReachable(User $user): bool;

    public function isAccountEnabled(User $user): bool;
}
