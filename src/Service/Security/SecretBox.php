<?php

declare(strict_types=1);

namespace App\Service\Security;

/** Output = base64(nonce ‖ ciphertext); key = 32 raw bytes or 64 hex chars. */
final class SecretBox implements SecretBoxInterface
{
    private string $key;

    public function __construct(string $key)
    {
        $decoded = ctype_xdigit($key) && 64 === strlen($key) ? sodium_hex2bin($key) : $key;
        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($decoded)) {
            throw new \InvalidArgumentException('SecretBox key must be 32 bytes (or 64 hex chars).');
        }
        $this->key = $decoded;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce.$cipher);
    }

    public function decrypt(string $ciphertext): ?string
    {
        $raw = base64_decode($ciphertext, true);
        if (false === $raw || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);

        return false === $plain ? null : $plain;
    }
}
