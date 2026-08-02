<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enum\Settings\SettingType;

/**
 * Covariant on purpose — a null default (SettingDef<null>) must stay assignable to SettingDef<?string> accessor return types.
 *
 * @template-covariant T
 */
final readonly class SettingDef
{
    /**
     * @param T                          $default
     * @param ?class-string<\BackedEnum> $enumClass  required when type === Enum
     * @param ?\Closure(mixed): mixed    $normalizer applied to incoming values before comparison + storage
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public mixed $default,
        public bool $secret = false,
        public ?string $enumClass = null,
        public ?\Closure $normalizer = null,
    ) {
    }

    public function normalize(mixed $value): mixed
    {
        return null === $this->normalizer ? $value : ($this->normalizer)($value);
    }
}
