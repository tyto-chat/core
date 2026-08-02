<?php

declare(strict_types=1);

namespace App\Dto\User;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

final readonly class ChangePasswordDto
{
    public function __construct(
        #[NotBlank]
        public string $currentPassword = '',
        #[NotBlank]
        #[Length(min: 8)]
        public string $newPassword = '',
    ) {
    }
}
