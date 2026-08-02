<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\User\PasswordResetDto;
use App\Service\User\ResetPasswordRequestServiceInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidResetPasswordRequestValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ResetPasswordRequestServiceInterface $resetPasswordRequestService,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidResetPasswordRequest) {
            throw new UnexpectedTypeException($constraint, ValidResetPasswordRequest::class);
        }

        if (!$value instanceof PasswordResetDto) {
            return;
        }

        if (!$this->resetPasswordRequestService->isValid($value->email, $value->token)) {
            $this->context->buildViolation($constraint->message)
                ->atPath('token')
                ->addViolation();
        }
    }
}
