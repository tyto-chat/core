<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class ValidResetPasswordRequest extends Constraint
{
    public string $message = 'valid_reset_password_request.invalid';

    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
