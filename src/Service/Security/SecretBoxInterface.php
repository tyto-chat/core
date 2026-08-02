<?php

declare(strict_types=1);

namespace App\Service\Security;

interface SecretBoxInterface
{
    public function encrypt(string $plaintext): string;

    /** Returns null when the ciphertext is malformed or fails authentication. */
    public function decrypt(string $ciphertext): ?string;
}
