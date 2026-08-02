<?php

declare(strict_types=1);

namespace App\Validator;

use App\Entity\ResetPasswordRequest;
use App\Service\User\UserServiceInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class EmailExistsValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserServiceInterface $userService,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EmailExists) {
            throw new UnexpectedTypeException($constraint, EmailExists::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof ResetPasswordRequest) {
            return;
        }

        if (!$this->userService->existsByEmail($value->getEmail())) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
