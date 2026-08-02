<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Dto\User\PasswordResetDto;
use App\Service\User\ResetPasswordRequestServiceInterface;
use App\Validator\ValidResetPasswordRequest;
use App\Validator\ValidResetPasswordRequestValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

#[AllowMockObjectsWithoutExpectations]
class ValidResetPasswordRequestValidatorTest extends TestCase
{
    private ResetPasswordRequestServiceInterface&MockObject $resetPasswordRequestService;
    private ExecutionContextInterface&MockObject $context;
    private ConstraintViolationBuilderInterface&MockObject $violationBuilder;

    #[\Override]
    protected function setUp(): void
    {
        $this->resetPasswordRequestService = $this->createMock(ResetPasswordRequestServiceInterface::class);
        $this->violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->violationBuilder->method('atPath')->willReturnSelf();
        $this->context->method('buildViolation')->willReturn($this->violationBuilder);
    }

    private function validator(): ValidResetPasswordRequestValidator
    {
        $v = new ValidResetPasswordRequestValidator($this->resetPasswordRequestService);
        $v->initialize($this->context);

        return $v;
    }

    private function dto(string $email = 'user@example.com', string $token = 'reset-token'): PasswordResetDto
    {
        return new PasswordResetDto(email: $email, token: $token, password: 'newpassword1');
    }

    public function testSkipsForNonPasswordResetDtoValue(): void
    {
        $this->resetPasswordRequestService->expects(self::never())->method('isValid');

        $this->validator()->validate(new \stdClass(), new ValidResetPasswordRequest());
    }

    public function testSkipsForNullValue(): void
    {
        $this->resetPasswordRequestService->expects(self::never())->method('isValid');

        $this->validator()->validate(null, new ValidResetPasswordRequest());
    }

    public function testPassesWhenResetRequestExists(): void
    {
        $this->resetPasswordRequestService
            ->expects(self::once())
            ->method('isValid')
            ->with('user@example.com', 'reset-token')
            ->willReturn(true);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator()->validate($this->dto(), new ValidResetPasswordRequest());
    }

    public function testAddsViolationWhenNoResetRequestFound(): void
    {
        $this->resetPasswordRequestService->method('isValid')->willReturn(false);

        $constraint = new ValidResetPasswordRequest();
        $this->context->expects(self::once())->method('buildViolation')->with($constraint->message);
        $this->violationBuilder->expects(self::once())->method('addViolation');

        $this->validator()->validate($this->dto(), $constraint);
    }

    public function testThrowsForWrongConstraintType(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator()->validate($this->dto(), $this->createMock(Constraint::class));
    }
}
