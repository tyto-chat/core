<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Service\HttpCache\HttpCacheQuarantineInterface;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemoryHttpCacheQuarantine;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class HttpCacheContextHashTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private static function sMaxAge(string $cacheControl): int
    {
        if (1 !== preg_match('/s-maxage=(\d+)/', $cacheControl, $m)) {
            self::fail('No s-maxage in '.$cacheControl);
        }

        return (int) $m[1];
    }

    private function setContextTtl(int $seconds): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['httpCacheContextTtlSeconds' => $seconds],
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testHashIsNotStoredWhenContextTtlIsZero(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('ctx-off')->create();

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/ctx-off'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        self::assertArrayNotHasKey('surrogate-key', $response->getHeaders());
    }

    public function testHashIsCacheableAndTaggedForPurging(): void
    {
        $this->setContextTtl(600);
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ctx-on')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/ctx-on/emojis'],
        ]);

        self::assertResponseStatusCodeSame(204);
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=600', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertArrayNotHasKey('vary', $headers);
        self::assertSame(
            sprintf('ctx-u%d, ctx-c%d', $user->getId(), $community->getId()),
            $headers['surrogate-key'][0],
        );
        self::assertArrayHasKey('x-user-context-hash', $headers);
    }

    public function testHashIsNotStoredWhileItsCommunityIsQuarantined(): void
    {
        $this->setContextTtl(600);
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ctx-quarantined')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $client = $this->jsonClient($user);
        $quarantine = self::getContainer()->get(HttpCacheQuarantineInterface::class);
        \assert($quarantine instanceof InMemoryHttpCacheQuarantine);
        $quarantine->quarantineCommunity((int) $community->getId());

        $response = $client->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/ctx-quarantined/emojis'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
    }

    public function testHashIsNotStoredWhileItsUserIsQuarantined(): void
    {
        $this->setContextTtl(600);
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('ctx-user-quarantined')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $client = $this->jsonClient($user);
        $quarantine = self::getContainer()->get(HttpCacheQuarantineInterface::class);
        \assert($quarantine instanceof InMemoryHttpCacheQuarantine);
        $quarantine->quarantineUser((int) $user->getId());

        $response = $client->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/ctx-user-quarantined/emojis'],
        ]);

        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
    }

    public function testHashLifetimeNeverOutlivesTheAccessToken(): void
    {
        $this->setContextTtl(600);
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('ctx-exp')->create();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, ['exp' => time() + 40]);

        $response = static::createClient()->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['Authorization' => 'Bearer '.$jwt, 'X-Forwarded-Uri' => '/api/v1/communities/ctx-exp'],
        ]);

        self::assertResponseStatusCodeSame(204);
        $maxAge = self::sMaxAge($response->getHeaders()['cache-control'][0]);
        self::assertGreaterThan(0, $maxAge);
        self::assertLessThanOrEqual(40, $maxAge);
    }

    public function testLowercaseSchemeAndUnreadableTokensStillRespectTokenLifetime(): void
    {
        $this->setContextTtl(600);
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('ctx-case')->create();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->createFromPayload($user, ['exp' => time() + 40]);

        $response = static::createClient()->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['Authorization' => 'bearer   '.$jwt, 'X-Forwarded-Uri' => '/api/v1/communities/ctx-case'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertLessThanOrEqual(40, self::sMaxAge($response->getHeaders()['cache-control'][0]));
    }

    public function testCookieOnlyCallerIsNeverGivenACacheableHash(): void
    {
        $this->setContextTtl(600);
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('ctx-cookie')->create();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $response = static::createClient()->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['Cookie' => 'BEARER='.$jwt, 'X-Forwarded-Uri' => '/api/v1/communities/ctx-cookie'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        self::assertArrayNotHasKey('surrogate-key', $response->getHeaders());
    }

    public function testAnonymousHashCarriesOnlyTheCommunityTag(): void
    {
        $this->setContextTtl(600);
        $community = CommunityFactory::new()->withIdentifier('ctx-anon')->create();

        $response = static::createClient()->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/ctx-anon'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(sprintf('ctx-c%d', $community->getId()), $response->getHeaders()['surrogate-key'][0]);
    }
}
