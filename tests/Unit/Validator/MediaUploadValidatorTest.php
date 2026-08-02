<?php

declare(strict_types=1);

namespace App\Tests\Unit\Validator;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use App\Validator\Constraint\MediaUpload;
use App\Validator\Constraint\MediaUploadValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
class MediaUploadValidatorTest extends TestCase
{
    /** Build a settings mock that returns sensible defaults for every registry key. */
    private function makeSettings(int $attachmentMaxSizeMb = 25): SettingsServiceInterface
    {
        $settings = $this->createMock(SettingsServiceInterface::class);

        $settings->method('get')->willReturnCallback(static function (mixed $def) use ($attachmentMaxSizeMb): mixed {
            return match ($def->key) {
                Settings::avatarMaxSizeMb()->key => 1,
                Settings::avatarMaxWidth()->key => 1000,
                Settings::avatarMaxHeight()->key => 1000,
                Settings::logoMaxSizeMb()->key => 1,
                Settings::logoMaxWidth()->key => 1000,
                Settings::logoMaxHeight()->key => 1000,
                Settings::maxAttachmentSizeMb()->key => $attachmentMaxSizeMb,
                Settings::attachmentAllowedMimes()->key => 'image/jpeg,image/png,application/pdf',
                Settings::communityEmojiMaxSizeMb()->key => 1,
                Settings::communityEmojiAllowedMimes()->key => 'image/png,image/webp,image/gif',
                default => throw new \UnexpectedValueException('Unexpected setting key: '.$def->key),
            };
        });

        return $settings;
    }

    private function makeValidator(ConstraintViolationList $violations, int $attachmentMaxSizeMb = 25): MediaUploadValidator
    {
        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator->method('validate')->willReturn($violations);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('getValidator')->willReturn($innerValidator);

        $context->method('buildViolation')->willReturn(
            $this->createMock(\Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface::class)
        );

        $validator = new MediaUploadValidator($this->makeSettings($attachmentMaxSizeMb));
        $validator->initialize($context);

        return $validator;
    }

    public function testAttachmentWithNoViolationsPassesThrough(): void
    {
        $validator = $this->makeValidator(new ConstraintViolationList());
        $file = $this->createMock(File::class);

        // Should not throw
        $validator->validate($file, new MediaUpload(type: 'attachment', groups: ['attachment']));

        $this->addToAssertionCount(1);
    }

    public function testAttachmentViolationsAreForwarded(): void
    {
        $violation = $this->createMock(ConstraintViolation::class);
        $violation->method('getMessageTemplate')->willReturn('File is too large.');
        $violation->method('getParameters')->willReturn([]);
        $violation->method('getCode')->willReturn(null);

        $list = new ConstraintViolationList([$violation]);

        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator->method('validate')->willReturn($list);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('getValidator')->willReturn($innerValidator);

        $violationBuilder = $this->createMock(\Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameters')->willReturnSelf();
        $violationBuilder->method('setCode')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $context->expects(self::once())
            ->method('buildViolation')
            ->with('File is too large.')
            ->willReturn($violationBuilder);

        $validator = new MediaUploadValidator($this->makeSettings());
        $validator->initialize($context);

        $file = $this->createMock(File::class);
        $validator->validate($file, new MediaUpload(type: 'attachment', groups: ['attachment']));
    }

    public function testNullValueSkipsValidation(): void
    {
        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator->expects(self::never())->method('validate');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('getValidator')->willReturn($innerValidator);

        $validator = new MediaUploadValidator($this->makeSettings());
        $validator->initialize($context);

        $validator->validate(null, new MediaUpload(type: 'attachment', groups: ['attachment']));
    }

    public function testUnknownTypeThrowsInvalidArgument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $validator = $this->makeValidator(new ConstraintViolationList());
        $file = $this->createMock(File::class);

        $validator->validate($file, new MediaUpload(type: 'unknown', groups: []));
    }

    /**
     * Bug-fix proof: the attachment size limit now comes from the registry.
     *
     * We intercept the FileConstraint that MediaUploadValidator passes to the inner
     * validator and assert that its maxSize property reflects exactly what the
     * settings stub returns — not any frozen env value. This directly proves the
     * registry is the source of truth for the limit.
     */
    public function testAttachmentSizeLimitIsReadFromRegistry(): void
    {
        $capturedConstraint = null;

        $innerValidator = $this->createMock(ValidatorInterface::class);
        $innerValidator->method('validate')->willReturnCallback(
            static function (mixed $value, mixed $constraint) use (&$capturedConstraint): ConstraintViolationList {
                $capturedConstraint = $constraint;

                return new ConstraintViolationList();
            }
        );

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->method('getValidator')->willReturn($innerValidator);
        $context->method('buildViolation')->willReturn(
            $this->createMock(\Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface::class)
        );

        // Registry says 7 MB — a value that would never appear in env defaults.
        $validator = new MediaUploadValidator($this->makeSettings(attachmentMaxSizeMb: 7));
        $validator->initialize($context);

        $file = $this->createMock(File::class);
        $validator->validate($file, new MediaUpload(type: 'attachment', groups: ['attachment']));

        self::assertNotNull($capturedConstraint, 'Inner validate() was never called.');
        self::assertInstanceOf(\Symfony\Component\Validator\Constraints\File::class, $capturedConstraint);
        // Symfony normalises 'NM' strings to bytes internally when the constraint is built.
        // '7M' → 7 * 1000 * 1000 = 7_000_000 bytes (Symfony uses SI, not IEC, for the 'M' suffix).
        self::assertSame(7_000_000, $capturedConstraint->maxSize,
            'maxAttachmentSizeMb=7 in registry must produce 7_000_000 bytes on the inner FileConstraint.'
        );
    }

    public function testAttachmentAllowedMimesAreParsedFromCsv(): void
    {
        // Verify the validator constructs without throwing when CSV has whitespace.
        $settings = $this->createMock(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(static function (mixed $def): mixed {
            return match ($def->key) {
                Settings::avatarMaxSizeMb()->key => 1,
                Settings::avatarMaxWidth()->key => 1000,
                Settings::avatarMaxHeight()->key => 1000,
                Settings::logoMaxSizeMb()->key => 1,
                Settings::logoMaxWidth()->key => 1000,
                Settings::logoMaxHeight()->key => 1000,
                Settings::maxAttachmentSizeMb()->key => 10,
                Settings::attachmentAllowedMimes()->key => 'image/jpeg, image/png , application/pdf',
                Settings::communityEmojiMaxSizeMb()->key => 1,
                Settings::communityEmojiAllowedMimes()->key => 'image/png,image/webp',
                default => throw new \UnexpectedValueException('Unexpected setting key: '.$def->key),
            };
        });

        $validator = new MediaUploadValidator($settings);

        $this->addToAssertionCount(1); // construction without exception is the assertion
    }
}
