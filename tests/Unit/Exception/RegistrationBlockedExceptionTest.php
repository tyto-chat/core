<?php

declare(strict_types=1);

namespace App\Tests\Unit\Exception;

use App\Exception\User\RegistrationBlockedException;
use PHPUnit\Framework\TestCase;

final class RegistrationBlockedExceptionTest extends TestCase
{
    public function testGenericWithoutContact(): void
    {
        $e = new RegistrationBlockedException();
        self::assertSame(422, $e->statusCode());
        self::assertSame('exception.registration_blocked', $e->translationKey());
    }

    public function testContactVariant(): void
    {
        $e = new RegistrationBlockedException('admin@example.com');
        self::assertSame('exception.registration_blocked_contact', $e->translationKey());
        self::assertSame(['%contact%' => 'admin@example.com'], $e->translationParams());
    }

    public function testEmptyContactFallsBackToGeneric(): void
    {
        $e = new RegistrationBlockedException('');
        self::assertSame('exception.registration_blocked', $e->translationKey());
    }
}
