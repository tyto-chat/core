<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Utils\ApiVersions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VersionsEndpointTest extends WebTestCase
{
    public function testVersionsEndpointIsAnonymousAndMirrorsRegistry(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/versions');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $cacheControl = $client->getResponse()->headers->get('cache-control') ?? '';
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=60', $cacheControl);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(ApiVersions::VERSIONS, $body['versions']);
        self::assertSame(ApiVersions::FEATURES, $body['features']);
        self::assertSame(['versions', 'features'], array_keys($body));
    }

    public function testFeatureRegistryCoversTheFrozenKeySet(): void
    {
        self::assertSame(
            ['auth', 'messaging', 'threads', 'dms', 'search', 'reactions', 'voice', 'presence', 'notifications', 'webPush', 'moderation', 'webhooks', 'embeds', 'admin'],
            array_keys(ApiVersions::FEATURES),
        );
        self::assertContains(ApiVersions::CANONICAL, ApiVersions::VERSIONS);
        self::assertSame('v1', ApiVersions::routeRequirement());
    }
}
