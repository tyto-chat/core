<?php

declare(strict_types=1);

namespace App\Dto\User;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final readonly class ConfirmTwoFactorDto
{
    public function __construct(
        #[NotBlank]
        #[Length(max: 32)]
        public string $code = '',
    ) {
    }
}
