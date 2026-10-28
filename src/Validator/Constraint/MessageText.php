<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class MessageText extends Constraint
{
    public const int PLAINTEXT_MAX_CHARS = 10000;

    public string $envelopeTooLongMessage = 'message_text.envelope_too_long';
}
