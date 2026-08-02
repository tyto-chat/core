<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Gdpr\ExportDownloadLimiter;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

class ExportDownloadLimiterTest extends TestCase
{
    /** @var list<array{string, array<int, mixed>}> */
    private array $calls = [];

    private function buildLimiter(int $incrReturnValue): ExportDownloadLimiter
    {
        $this->calls = [];
        $recordCalls = function (string $name, array $args) use ($incrReturnValue): int {
            $this->calls[] = [$name, $args];

            return 'incr' === $name ? $incrReturnValue : 1;
        };

        $redis = self::createStub(ClientInterface::class);
        $redis->method('__call')->willReturnCallback($recordCalls);

        return new ExportDownloadLimiter($redis);
    }

    public function testFirstRedemptionSetsTtlAndPasses(): void
    {
        $limiter = $this->buildLimiter(1);

        self::assertTrue($limiter->incrementAndCheck('tok', 3600));

        self::assertSame('incr', $this->calls[0][0]);
        self::assertSame('expire', $this->calls[1][0]);
        self::assertStringStartsWith('export_dl:', (string) $this->calls[0][1][0]);
        // Verify the key is hashed (no raw "tok" in keyspace).
        self::assertStringNotContainsString('tok', substr((string) $this->calls[0][1][0], strlen('export_dl:')));
    }

    public function testTtlOnlySetOnFirstIncrement(): void
    {
        $limiter = $this->buildLimiter(2);

        self::assertTrue($limiter->incrementAndCheck('tok', 3600));

        self::assertCount(1, $this->calls);
        self::assertSame('incr', $this->calls[0][0]);
    }

    public function testReturnsFalseWhenCapExceeded(): void
    {
        $limiter = $this->buildLimiter(ExportDownloadLimiter::MAX_DOWNLOADS + 1);

        self::assertFalse($limiter->incrementAndCheck('tok', 3600));
    }

    public function testReturnsTrueAtExactlyMaxDownloads(): void
    {
        $limiter = $this->buildLimiter(ExportDownloadLimiter::MAX_DOWNLOADS);

        self::assertTrue($limiter->incrementAndCheck('tok', 3600));
    }
}
