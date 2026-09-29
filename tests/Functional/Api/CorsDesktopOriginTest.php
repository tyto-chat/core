<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\DependencyInjection\CorsOriginEnvVarProcessor;
use App\Tests\Functional\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class CorsDesktopOriginTest extends ApiTestCase
{
    public function testPreflightFromTheDesktopAppIsAnswered(): void
    {
        $response = static::createClient()->request('OPTIONS', '/api/versions', [
            'headers' => [
                'Origin' => CorsOriginEnvVarProcessor::DESKTOP_APP_ORIGIN,
                'Access-Control-Request-Method' => 'GET',
                'Access-Control-Request-Headers' => 'authorization, content-type, x-token-transport',
            ],
        ]);

        $headers = $response->getHeaders(false);

        self::assertSame(['app://tyto'], $headers['access-control-allow-origin'] ?? null);
        self::assertStringContainsStringIgnoringCase(
            'x-token-transport',
            implode(',', $headers['access-control-allow-headers'] ?? []),
        );
    }

    public function testAnActualRequestFromTheDesktopAppCarriesTheHeader(): void
    {
        $response = static::createClient()->request('GET', '/api/versions', [
            'headers' => ['Origin' => CorsOriginEnvVarProcessor::DESKTOP_APP_ORIGIN],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['app://tyto'],
            $response->getHeaders(false)['access-control-allow-origin'] ?? null,
        );
    }

    /** @return iterable<string, array{string}> */
    public static function foreignOrigins(): iterable
    {
        yield 'another app host' => ['app://evil'];
        yield 'look-alike host' => ['app://tyto.evil.net'];
        yield 'a website' => ['https://evil.example'];
        yield 'https with the same name' => ['https://tyto'];
    }

    #[DataProvider('foreignOrigins')]
    public function testOtherOriginsGetNoPermission(string $origin): void
    {
        $response = static::createClient()->request('OPTIONS', '/api/versions', [
            'headers' => ['Origin' => $origin, 'Access-Control-Request-Method' => 'GET'],
        ]);

        self::assertArrayNotHasKey('access-control-allow-origin', $response->getHeaders(false));
    }
}
