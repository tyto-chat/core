<?php

declare(strict_types=1);

namespace App\Tests\Unit\Health\Check;

use App\Enum\Health\HealthStatus;
use App\Health\Check\MeilisearchHealthCheck;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MeilisearchHealthCheckTest extends TestCase
{
    public function testReturnsOkOn200(): void
    {
        $client = new MockHttpClient(new MockResponse('{"status":"available"}', ['http_code' => 200]));

        $result = new MeilisearchHealthCheck($client, 'http://meili:7700')->check();

        self::assertSame('meilisearch', $result->name);
        self::assertSame(HealthStatus::Ok, $result->status);
    }

    public function testReturnsDownOnNon200(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        $result = new MeilisearchHealthCheck($client, 'http://meili:7700')->check();

        self::assertSame(HealthStatus::Down, $result->status);
        self::assertStringContainsString('503', (string) $result->error);
    }

    public function testReturnsDownOnTransportError(): void
    {
        $client = new MockHttpClient(static function () {
            throw new \RuntimeException('connection refused');
        });

        $result = new MeilisearchHealthCheck($client, 'http://meili:7700')->check();

        self::assertSame(HealthStatus::Down, $result->status);
    }
}
