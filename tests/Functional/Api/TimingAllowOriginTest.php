<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\DependencyInjection\CorsOriginEnvVarProcessor;
use App\Tests\Functional\ApiTestCase;

class TimingAllowOriginTest extends ApiTestCase
{
    public function testAllowedCrossOriginResponsesExposeResourceTiming(): void
    {
        $response = static::createClient()->request('GET', '/api/versions', [
            'headers' => ['Origin' => CorsOriginEnvVarProcessor::DESKTOP_APP_ORIGIN],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(['app://tyto'], $response->getHeaders(false)['timing-allow-origin'] ?? null);
    }

    public function testForeignOriginsGetNoTimingPermission(): void
    {
        $response = static::createClient()->request('GET', '/api/versions', [
            'headers' => ['Origin' => 'https://evil.example'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('timing-allow-origin', $response->getHeaders(false));
    }

    public function testSameOriginResponsesCarryNoHeader(): void
    {
        $response = static::createClient()->request('GET', '/api/versions');

        self::assertResponseIsSuccessful();
        self::assertArrayNotHasKey('timing-allow-origin', $response->getHeaders(false));
    }
}
