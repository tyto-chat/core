<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\File as FileConstraint;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class MediaUploadValidator extends ConstraintValidator
{
    private const AVATAR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const LOGO_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MediaUpload) {
            throw new UnexpectedTypeException($constraint, MediaUpload::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof File) {
            throw new UnexpectedTypeException($value, File::class);
        }

        $innerConstraint = match ($constraint->type) {
            'avatar' => new Image(
                maxSize: $this->settings->get(Settings::avatarMaxSizeMb()).'M',
                mimeTypes: self::AVATAR_MIME_TYPES,
                maxWidth: $this->settings->get(Settings::avatarMaxWidth()),
                maxHeight: $this->settings->get(Settings::avatarMaxHeight()),
            ),
            'logo' => new Image(
                maxSize: $this->settings->get(Settings::logoMaxSizeMb()).'M',
                mimeTypes: self::LOGO_MIME_TYPES,
                maxWidth: $this->settings->get(Settings::logoMaxWidth()),
                maxHeight: $this->settings->get(Settings::logoMaxHeight()),
            ),
            'attachment' => new FileConstraint(
                maxSize: $this->settings->get(Settings::maxAttachmentSizeMb()).'M',
                mimeTypes: array_filter(array_map('trim', explode(',', $this->settings->get(Settings::attachmentAllowedMimes())))),
                mimeTypesMessage: 'This file type is not allowed. Allowed types: {{ types }}.',
            ),
            'community_emoji' => new Image(
                maxSize: $this->settings->get(Settings::communityEmojiMaxSizeMb()).'M',
                mimeTypes: array_filter(array_map('trim', explode(',', $this->settings->get(Settings::communityEmojiAllowedMimes())))),
                maxWidth: $this->settings->get(Settings::communityEmojiMaxWidth()),
                maxHeight: $this->settings->get(Settings::communityEmojiMaxHeight()),
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown media upload type "%s".', $constraint->type)),
        };

        $violations = $this->context->getValidator()->validate($value, $innerConstraint);

        foreach ($violations as $violation) {
            $this->context
                ->buildViolation($violation->getMessageTemplate())
                ->setParameters($violation->getParameters())
                ->setCode($violation->getCode())
                ->addViolation();
        }
    }
}
