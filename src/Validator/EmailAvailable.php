<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_PROPERTY)]
class EmailAvailable extends Constraint
{
    public string $message = 'email_available.already_registered';

    #[\Override]
    public function getTargets(): string|array
    {
        return [self::CLASS_CONSTRAINT, self::PROPERTY_CONSTRAINT];
    }
}
