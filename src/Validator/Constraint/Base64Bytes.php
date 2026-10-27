<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Base64Bytes extends Constraint
{
    public string $message = 'base64_bytes.invalid';
    public string $maxMessage = 'base64_bytes.too_long';
    public string $formatMessage = 'base64_bytes.not_base64';

    public function __construct(
        public readonly ?int $length = null,
        public readonly ?int $max = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
