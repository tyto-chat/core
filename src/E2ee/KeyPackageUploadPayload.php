<?php

declare(strict_types=1);

namespace App\E2ee;

final class KeyPackageUploadPayload
{
    /**
     * @param list<array{data: string, expiresAt: int}> $packages
     * @param array{data: string, expiresAt: int}|null  $lastResort
     * @param string|null                               $lastResortReplaces raw SHA-256 of the fallback being replaced
     */
    public static function encode(string $deviceId, int $generation, array $packages, ?array $lastResort, ?string $lastResortReplaces): string
    {
        return CanonicalCbor::encodeMap([
            'deviceId' => $deviceId,
            'generation' => $generation,
            'packages' => new ByteString(self::digest($packages)),
            'lastResort' => new ByteString(self::digest(null === $lastResort ? [] : [$lastResort])),
            'replaces' => new ByteString($lastResortReplaces ?? ''),
        ]);
    }

    /** @param list<array{data: string, expiresAt: int}> $packages */
    private static function digest(array $packages): string
    {
        $entries = '';
        foreach ($packages as $package) {
            $entries .= hash('sha256', (string) base64_decode($package['data'], true), true).pack('J', $package['expiresAt']);
        }

        return hash('sha256', $entries, true);
    }
}
