<?php

declare(strict_types=1);

namespace App\Dto\User;

use Symfony\Component\Validator\Constraints\NotBlank;

final readonly class CurrentPasswordDto
{
    public function __construct(
        #[NotBlank]
        public string $currentPassword = '',
    ) {
    }
}
