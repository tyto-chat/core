<?php

declare(strict_types=1);

namespace App\Dto\ApiKey;

use App\Entity\ApiKey;

// The plaintext token exists only here — never persisted; surface it to the user once and never store it.
final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $key,
        public string $plainToken,
    ) {
    }
}
