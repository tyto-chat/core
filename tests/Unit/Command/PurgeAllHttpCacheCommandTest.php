<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\PurgeAllHttpCacheCommand;
use App\Service\HttpCache\HttpCacheFlusherInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeAllHttpCacheCommandTest extends TestCase
{
    public function testFlushesTheCacheAndReportsTheCount(): void
    {
        $flusher = $this->createMock(HttpCacheFlusherInterface::class);
        $flusher->expects(self::once())->method('flushAll')->willReturn(42);

        $tester = new CommandTester(new PurgeAllHttpCacheCommand($flusher));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('purged 42 keys', $tester->getDisplay());
    }
}
