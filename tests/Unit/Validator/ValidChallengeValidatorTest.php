<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Dto\User\CreateUserDto;
use App\Service\Challenge\ChallengeServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use App\Validator\ValidChallenge;
use App\Validator\ValidChallengeValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

#[AllowMockObjectsWithoutExpectations]
class ValidChallengeValidatorTest extends TestCase
{
    private ChallengeServiceInterface&MockObject $challengeService;
    private ExecutionContextInterface&MockObject $context;
    private ConstraintViolationBuilderInterface&MockObject $violationBuilder;

    #[\Override]
    protected function setUp(): void
    {
        $this->challengeService = $this->createMock(ChallengeServiceInterface::class);
        $this->violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->violationBuilder->method('atPath')->willReturnSelf();
        $this->context->method('buildViolation')->willReturn($this->violationBuilder);
    }

    private function validator(bool $enabled = true): ValidChallengeValidator
    {
        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(static fn ($def) => match ($def->key) {
            Settings::validateEmails()->key => $enabled,
            default => throw new \LogicException("Unexpected setting: {$def->key}"),
        });

        $v = new ValidChallengeValidator($this->challengeService, $settings);
        $v->initialize($this->context);

        return $v;
    }

    private function dto(string $email = 'user@example.com', ?string $token = 'tok123'): CreateUserDto
    {
        return new CreateUserDto($email, 'password1', 'Test User', $token);
    }

    public function testSkipsAllValidationWhenEmailValidationDisabled(): void
    {
        $this->challengeService->expects(self::never())->method('isValid');
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator(false)->validate($this->dto(), new ValidChallenge());
    }

    public function testSkipsForNullValue(): void
    {
        $this->challengeService->expects(self::never())->method('isValid');

        $this->validator()->validate(null, new ValidChallenge());
    }

    public function testSkipsForEmptyStringValue(): void
    {
        $this->challengeService->expects(self::never())->method('isValid');

        $this->validator()->validate('', new ValidChallenge());
    }

    public function testSkipsForNonUserDtoValue(): void
    {
        $this->challengeService->expects(self::never())->method('isValid');

        $this->validator()->validate(new \stdClass(), new ValidChallenge());
    }

    public function testPassesWhenValidChallengeExists(): void
    {
        $this->challengeService
            ->expects(self::once())
            ->method('isValid')
            ->with('user@example.com', 'tok123')
            ->willReturn(true);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator()->validate($this->dto(), new ValidChallenge());
    }

    public function testAddsViolationWhenChallengeNotFound(): void
    {
        $this->challengeService
            ->method('isValid')
            ->willReturn(false);

        $constraint = new ValidChallenge();
        $this->context->expects(self::once())->method('buildViolation')->with($constraint->message);
        $this->violationBuilder->expects(self::once())->method('addViolation');

        $this->validator()->validate($this->dto(), $constraint);
    }

    public function testAddsViolationForNullTokenWithoutCallingIsValid(): void
    {
        // Regression: a missing challenge token used to be passed to
        // isValid(string $token) → TypeError → HTTP 500. It must instead become
        // a validation violation (422), and isValid() must NOT be called with null.
        $this->challengeService->expects(self::never())->method('isValid');

        $constraint = new ValidChallenge();
        $this->context->expects(self::once())->method('buildViolation')->with($constraint->message);
        $this->violationBuilder->expects(self::once())->method('addViolation');

        $this->validator()->validate($this->dto(token: null), $constraint);
    }

    public function testAddsViolationForEmptyTokenWithoutCallingIsValid(): void
    {
        $this->challengeService->expects(self::never())->method('isValid');

        $constraint = new ValidChallenge();
        $this->context->expects(self::once())->method('buildViolation')->with($constraint->message);
        $this->violationBuilder->expects(self::once())->method('addViolation');

        $this->validator()->validate($this->dto(token: ''), $constraint);
    }

    public function testThrowsForWrongConstraintType(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator()->validate($this->dto(), $this->createMock(Constraint::class));
    }
}
