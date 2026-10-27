<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class Base64BytesValidator extends ConstraintValidator
{
    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Base64Bytes) {
            throw new UnexpectedTypeException($constraint, Base64Bytes::class);
        }
        if (null === $value) {
            return;
        }

        $decoded = \is_string($value) ? base64_decode($value, true) : false;
        if (false === $decoded || \strlen($decoded) !== $constraint->length || base64_encode($decoded) !== $value) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ length }}', (string) $constraint->length)
                ->addViolation();
        }
    }
}
