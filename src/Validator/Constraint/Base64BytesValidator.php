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
        if (false === $decoded || '' === $decoded || base64_encode($decoded) !== $value
            || (null !== $constraint->length && \strlen($decoded) !== $constraint->length)
        ) {
            $violation = null === $constraint->length
                ? $this->context->buildViolation($constraint->formatMessage)
                : $this->context->buildViolation($constraint->message)->setParameter('{{ length }}', (string) $constraint->length);
            $violation->addViolation();

            return;
        }

        if (null !== $constraint->max && \strlen($decoded) > $constraint->max) {
            $this->context->buildViolation($constraint->maxMessage)
                ->setParameter('{{ max }}', (string) $constraint->max)
                ->addViolation();
        }
    }
}
