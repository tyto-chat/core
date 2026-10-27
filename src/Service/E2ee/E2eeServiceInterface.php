<?php

declare(strict_types=1);

namespace App\Service\E2ee;

use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\User;
use App\Enum\Settings\EncryptedDmsMode;

interface E2eeServiceInterface
{
    public const int MAX_ACTIVE_DEVICES = 20;
    public const int MAX_ONE_TIME_KEY_PACKAGES = 100;
    public const int MAX_KEY_PACKAGE_LIFETIME_SECONDS = 366 * 86400;

    public function registerAccount(string $publicKey): E2eeAccount;

    public function registerDevice(string $id, string $name, string $publicKey, string $server, string $credential): E2eeDevice;

    /** @return list<array{device: E2eeDevice, lastResortFingerprint: ?string}> fingerprint of the newest fallback the device published, hex */
    public function listOwnDevices(): array;

    /** @return array{account: ?E2eeAccount, devices: list<E2eeDevice>} */
    public function listDevices(int $userId): array;

    /** @return list<array{device: E2eeDevice, signer: E2eeDevice}> revoked devices with the device that signed each revocation */
    public function listRevocations(int $userId): array;

    public function revokeDevice(string $deviceId, string $signerDeviceId, int $revokedAt, string $signature): void;

    /**
     * @param list<array{data: string, expiresAt: int}> $packages
     * @param array{data: string, expiresAt: int}|null  $lastResort
     * @param string|null                               $lastResortReplaces base64 SHA-256 of the fallback a new fallback replaces
     * @param string                                    $signature          base64 Ed25519 signature by the target device over KeyPackageUploadPayload
     */
    public function storeKeyPackages(string $deviceId, array $packages, ?array $lastResort, ?string $lastResortReplaces, string $signature): void;

    /** @return list<array{deviceId: string, keyPackage: string}> one package per reachable device; one-time packages are consumed */
    public function claimKeyPackages(int $userId): array;

    public function isReachable(User $user): bool;

    public function isAccountEnabled(User $user): bool;

    public function mode(): EncryptedDmsMode;

    /** Refuses new encryption setup while the operator mode is disabled; existing accounts keep working. */
    public function assertSetupAllowed(): void;
}
