<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

class ApiDocsUiTest extends ApiTestCase
{
    public function testSwaggerUiStartsCollapsedWithoutTheModelsSection(): void
    {
        $html = static::createClient()->request('GET', '/api/v1/docs', [
            'headers' => ['Accept' => 'text/html'],
        ])->getContent();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"docExpansion":"none"', $html);
        self::assertStringContainsString('"defaultModelsExpandDepth":-1', $html);
        self::assertStringContainsString('"filter":true', $html);
    }
}
