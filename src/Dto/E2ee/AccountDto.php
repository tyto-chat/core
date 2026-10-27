<?php

declare(strict_types=1);

namespace App\Dto\E2ee;

use App\Entity\E2eeAccount;
use Symfony\Component\Serializer\Attribute\Groups;

final readonly class AccountDto
{
    public function __construct(
        #[Groups(['e2ee:read'])]
        public string $publicKey,
        #[Groups(['e2ee:read'])]
        public int $generation,
    ) {
    }

    public static function fromAccount(E2eeAccount $account): self
    {
        return new self($account->getPublicKey(), $account->getGeneration());
    }
}
