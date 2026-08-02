<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CommunityInvite;
use PHPUnit\Framework\TestCase;

class CommunityInviteTest extends TestCase
{
    public function testFreshInviteIsValid(): void
    {
        self::assertTrue((new CommunityInvite())->isValid());
    }

    public function testExpiredInviteIsInvalid(): void
    {
        $invite = (new CommunityInvite())->setExpiresAt(new \DateTimeImmutable('-1 second'));

        self::assertFalse($invite->isValid());
    }

    public function testFutureExpiryIsValid(): void
    {
        $invite = (new CommunityInvite())->setExpiresAt(new \DateTimeImmutable('+1 hour'));

        self::assertTrue($invite->isValid());
    }

    public function testExhaustedInviteIsInvalid(): void
    {
        $invite = (new CommunityInvite())->setMaxUses(2)->setUseCount(2);

        self::assertFalse($invite->isValid());
    }

    public function testUsesRemainingIsValid(): void
    {
        $invite = (new CommunityInvite())->setMaxUses(2)->setUseCount(1);

        self::assertTrue($invite->isValid());
    }

    public function testUnlimitedUsesIsValid(): void
    {
        $invite = (new CommunityInvite())->setUseCount(999);

        self::assertTrue($invite->isValid());
    }

    public function testIncrementUseCount(): void
    {
        $invite = (new CommunityInvite())->setUseCount(4);
        $invite->incrementUseCount();

        self::assertSame(5, $invite->getUseCount());
    }
}
