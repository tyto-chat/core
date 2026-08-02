<?php

declare(strict_types=1);

namespace App\Dto\User;

use App\Validator\ValidResetPasswordRequest;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

#[ValidResetPasswordRequest]
final readonly class PasswordResetDto
{
    public function __construct(
        #[NotBlank]
        #[Email]
        public string $email = '',
        #[NotBlank]
        public string $token = '',
        #[NotBlank]
        #[Length(min: 8, max: 64)]
        public string $password = '',
    ) {
    }
}
