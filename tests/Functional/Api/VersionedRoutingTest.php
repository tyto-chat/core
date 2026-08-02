<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class VersionedRoutingTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testServerInfoServesUnderV1WithVersionedIris(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v1/server-info');
        self::assertResponseIsSuccessful();
    }

    public function testUnversionedResourcePathIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/server-info');
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnsupportedVersionIs404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/v2/server-info');
        self::assertResponseStatusCodeSame(404);
    }

    public function testGeneratedIrisCarryTheRequestedVersion(): void
    {
        $client = static::createClient();
        // server-info is plain `json` (no JSON-LD format), so its embedded
        // communities never carry an `@id` at all — asserting on it there
        // would stay vacuous no matter what's seeded. The community detail
        // Get is JSON-LD and publicly readable, so it's a guaranteed-present
        // versioned IRI: seed a public community and read it back.
        $community = CommunityFactory::new()->withIdentifier('versioned-routing-iri-check')->create();

        $client->request(
            'GET',
            '/api/v1/communities/'.$community->getIdentifier(),
            server: ['HTTP_ACCEPT' => 'application/ld+json'],
        );

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('@id', $body);
        self::assertStringStartsWith('/api/v1/communities/'.$community->getIdentifier(), $body['@id']);
    }

    public function testDiscoveryAndHealthStayUnversioned(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/versions');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/health');
        self::assertContains($client->getResponse()->getStatusCode(), [200, 503]);
    }
}
