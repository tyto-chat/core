<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class EmailExists extends Constraint
{
    public string $message = 'email_exists.not_registered';

    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
