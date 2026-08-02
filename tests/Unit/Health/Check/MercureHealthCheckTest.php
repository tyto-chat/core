<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\Check\MercureHealthCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MercureHealthCheckTest extends TestCase
{
    public function testReturnsOkOn200(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 200]));

        $result = new MercureHealthCheck($client, 'http://mercure/.well-known/mercure')->check();

        self::assertSame('mercure', $result->name);
        self::assertSame(HealthStatus::Ok, $result->status);
    }

    public function testReturnsOkOn401Because401MeansHubIsUp(): void
    {
        // Mercure returns 401 to an authless GET — that's success, not failure.
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        $result = new MercureHealthCheck($client, 'http://mercure/.well-known/mercure')->check();

        self::assertSame(HealthStatus::Ok, $result->status);
    }

    public function testReturnsDownOn5xx(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 502]));

        $result = new MercureHealthCheck($client, 'http://mercure/.well-known/mercure')->check();

        self::assertSame(HealthStatus::Down, $result->status);
        self::assertStringContainsString('502', (string) $result->error);
    }

    public function testReturnsDownOnTransportError(): void
    {
        $client = new MockHttpClient(static function () {
            throw new \RuntimeException('connection refused');
        });

        $result = new MercureHealthCheck($client, 'http://mercure/.well-known/mercure')->check();

        self::assertSame(HealthStatus::Down, $result->status);
    }
}
