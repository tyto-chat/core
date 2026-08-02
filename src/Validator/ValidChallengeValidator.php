<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\User\CreateUserDto as UserDto;
use App\Service\Challenge\ChallengeServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidChallengeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ChallengeServiceInterface $challengeService,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$this->settings->get(Settings::validateEmails())) {
            return;
        }

        if (!$constraint instanceof ValidChallenge) {
            throw new UnexpectedTypeException($constraint, ValidChallenge::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof UserDto) {
            return;
        }

        $token = $value->challengeToken;
        if (null === $token || '' === $token || !$this->challengeService->isValid($value->email, $token)) {
            $this->context->buildViolation($constraint->message)
                ->atPath('challengeToken')
                ->addViolation();
        }
    }
}
