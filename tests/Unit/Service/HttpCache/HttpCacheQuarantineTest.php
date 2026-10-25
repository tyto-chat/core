<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\HttpCache;

use App\Service\HttpCache\HttpCacheQuarantine;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class HttpCacheQuarantineTest extends TestCase
{
    private function unreachable(): HttpCacheQuarantine
    {
        return new HttpCacheQuarantine(new Client('tcp://127.0.0.1:1?timeout=0.2', ['exceptions' => true]));
    }

    public function testUnreachableRedisCountsAsQuarantined(): void
    {
        self::assertTrue($this->unreachable()->isCommunityQuarantined(7));
        self::assertTrue($this->unreachable()->isUserQuarantined(7));
    }

    public function testQuarantiningFailsLoudlyWhenRedisIsUnreachable(): void
    {
        $this->expectException(\Throwable::class);
        $this->unreachable()->quarantineCommunity(7);
    }
}
