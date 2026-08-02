<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\Challenge;
use App\Service\User\UserServiceInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class EmailAvailableValidator extends ConstraintValidator
{
    public function __construct(private readonly UserServiceInterface $userService)
    {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EmailAvailable) {
            throw new UnexpectedTypeException($constraint, EmailAvailable::class);
        }

        if ($value instanceof Challenge) {
            $email = $value->getEmail();
        } elseif (is_string($value)) {
            $email = $value;
        } else {
            return;
        }

        if (null === $email || '' === $email) {
            return;
        }

        if ($this->userService->existsByEmail($email)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
