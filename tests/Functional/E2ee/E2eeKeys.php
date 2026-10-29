<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use App\E2ee\ByteString;
use App\E2ee\CanonicalCbor;
use Symfony\Component\Uid\Uuid;

final class E2eeKeys
{
    public const string SERVER = 'http://localhost';

    /** @var non-empty-string */
    public readonly string $accountKeyPair;

    public function __construct()
    {
        $this->accountKeyPair = sodium_crypto_sign_keypair();
    }

    public function accountPublicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey($this->accountKeyPair));
    }

    /** @return array{body: array{deviceId: string, name: string, publicKey: string, server: string, credential: string}, keyPair: non-empty-string} */
    public function device(int $accountId, int $generation = 1, string $server = self::SERVER, ?string $deviceId = null, string $name = 'Laptop'): array
    {
        $deviceKeyPair = sodium_crypto_sign_keypair();
        $devicePublicKey = sodium_crypto_sign_publickey($deviceKeyPair);
        $deviceId ??= Uuid::v4()->toRfc4122();

        $payload = CanonicalCbor::encodeMap([
            'server' => $server,
            'accountId' => $accountId,
            'deviceId' => $deviceId,
            'generation' => $generation,
            'devicePublicKey' => new ByteString($devicePublicKey),
        ]);

        return [
            'body' => [
                'deviceId' => $deviceId,
                'name' => $name,
                'publicKey' => base64_encode($devicePublicKey),
                'server' => $server,
                'credential' => base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($this->accountKeyPair))),
            ],
            'keyPair' => $deviceKeyPair,
        ];
    }

    /**
     * @param non-empty-string $deviceKeyPair
     *
     * @return array{leaveRequestId: string, requestedAt: int, signature: string}
     */
    public static function leaveRequest(string $deviceId, string $deviceKeyPair, int $generation = 1, ?string $leaveRequestId = null, ?int $requestedAt = null): array
    {
        $leaveRequestId ??= Uuid::v4()->toRfc4122();
        $requestedAt ??= time();
        $payload = CanonicalCbor::encodeMap([
            'deviceId' => $deviceId,
            'generation' => $generation,
            'requestedAt' => $requestedAt,
            'leaveRequestId' => $leaveRequestId,
        ]);

        return [
            'leaveRequestId' => $leaveRequestId,
            'requestedAt' => $requestedAt,
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($deviceKeyPair))),
        ];
    }

    /**
     * @param non-empty-string $signerKeyPair
     *
     * @return array{signerDeviceId: string, revokedAt: int, signature: string}
     */
    public static function revocation(string $deviceId, string $signerDeviceId, string $signerKeyPair, int $generation = 1, ?int $revokedAt = null): array
    {
        $revokedAt ??= time();
        $payload = CanonicalCbor::encodeMap([
            'deviceId' => $deviceId,
            'generation' => $generation,
            'revokedAt' => $revokedAt,
            'signerDeviceId' => $signerDeviceId,
        ]);

        return [
            'signerDeviceId' => $signerDeviceId,
            'revokedAt' => $revokedAt,
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($signerKeyPair))),
        ];
    }
}
