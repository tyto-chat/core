<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use App\Utils\E2eeEnvelope;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class MessageTextValidator extends ConstraintValidator
{
    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MessageText) {
            throw new UnexpectedTypeException($constraint, MessageText::class);
        }
        if (!\is_string($value)) {
            return;
        }

        if (!E2eeEnvelope::isEnvelope($value)) {
            $this->context->getValidator()->inContext($this->context)->validate($value, new Length(max: MessageText::PLAINTEXT_MAX_CHARS));

            return;
        }

        if (\strlen($value) > E2eeEnvelope::MAX_BYTES) {
            $this->context->buildViolation($constraint->envelopeTooLongMessage)
                ->setParameter('{{ limit }}', (string) E2eeEnvelope::MAX_BYTES)
                ->addViolation();
        }
    }
}
