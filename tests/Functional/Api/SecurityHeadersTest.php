<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class SecurityHeadersTest extends ApiTestCase
{
    public function testPublicResponseCarriesSecurityHeaders(): void
    {
        $client = self::createClient();
        $response = $client->request('GET', '/api/v1/server-info', [
            'headers' => ['Accept' => 'application/json'],
        ]);

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders(false);

        self::assertSame('nosniff', $headers['x-content-type-options'][0] ?? null);
        self::assertSame('strict-origin-when-cross-origin', $headers['referrer-policy'][0] ?? null);
        self::assertSame('deny', $headers['x-frame-options'][0] ?? null);
        self::assertArrayHasKey('content-security-policy-report-only', $headers);
    }
}
