<?php

declare(strict_types=1);

namespace App\Tests\Unit\ValueObject;

use App\ValueObject\User\BlockRelations;
use PHPUnit\Framework\TestCase;

final class BlockRelationsTest extends TestCase
{
    public function testDirectionsAreKeptApart(): void
    {
        $relations = new BlockRelations(blockedIds: [2], blockerIds: [3]);

        self::assertTrue($relations->blocks(2));
        self::assertFalse($relations->isBlockedBy(2));
        self::assertTrue($relations->isBlockedBy(3));
        self::assertFalse($relations->blocks(3));
        self::assertTrue($relations->hasAnyWith(2));
        self::assertTrue($relations->hasAnyWith(3));
        self::assertFalse($relations->hasAnyWith(4));
    }
}
