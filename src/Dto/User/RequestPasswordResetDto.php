<?php

declare(strict_types=1);

namespace App\Dto\User;

use App\Validator\EmailExists;
use Symfony\Component\Validator\Constraints as Assert;

#[EmailExists]
final readonly class RequestPasswordResetDto
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email = '',
    ) {
    }
}
