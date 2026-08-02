<?php

declare(strict_types=1);

namespace App\Enum\Settings;

enum SettingType
{
    case String;
    case Int;
    case Bool;
    case Float;
    case Enum;
    case DateTime;

    public function encode(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        return match ($this) {
            self::String => (string) $value,
            self::Int => (int) $value,
            self::Bool => (bool) $value,
            self::Float => (float) $value,
            self::Enum => $value instanceof \BackedEnum
                ? $value->value
                : throw new \LogicException('SettingType::Enum expects a BackedEnum value'),
            self::DateTime => $value instanceof \DateTimeInterface
                ? $value->format(\DateTimeInterface::ATOM)
                : (string) $value,
        };
    }

    /** @param ?class-string<\BackedEnum> $enumClass */
    public function decode(mixed $stored, ?string $enumClass): mixed
    {
        if (null === $stored) {
            return null;
        }

        return match ($this) {
            self::String => (string) $stored,
            self::Int => (int) $stored,
            self::Bool => \is_bool($stored) ? $stored : \in_array($stored, [1, '1', 'true', true], true),
            self::Float => (float) $stored,
            self::Enum => null !== $enumClass
                ? $enumClass::from($stored)
                : throw new \LogicException('enumClass is required for SettingType::Enum'),
            self::DateTime => new \DateTimeImmutable((string) $stored),
        };
    }
}
