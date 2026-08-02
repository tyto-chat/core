<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class MediaUpload extends Constraint
{
    public function __construct(
        public readonly string $type,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    #[\Override]
    public function validatedBy(): string
    {
        return MediaUploadValidator::class;
    }
}
