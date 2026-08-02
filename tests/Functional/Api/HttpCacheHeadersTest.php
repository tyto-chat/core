<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Channel;
use App\Entity\Community;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class HttpCacheHeadersTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function setHttpCacheTtl(int $n): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['httpCachePageTtlSeconds' => $n],
        ]);
        self::assertResponseIsSuccessful();
    }

    private function setPresenceTtl(int $n): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['httpCachePresenceTtlSeconds' => $n],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @return array{Client, Community, Channel} */
    private function memberClientWithChannelPage(): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cache-community')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cache-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $client = $this->jsonClient($user);
        $client->request('POST', '/api/v1/communities/cache-community/channels/cache-ch/messages', [
            'json' => ['text' => 'hello'],
        ]);
        self::assertResponseStatusCodeSame(201);

        return [$client, $community, $channel];
    }

    public function testPageResponseCarriesNoCacheHeadersByDefault(): void
    {
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/pages/1', $community->getIdentifier(), $channel->getIdentifier()));

        self::assertResponseIsSuccessful();
        $cc = $response->getHeaders()['cache-control'][0] ?? '';
        self::assertStringNotContainsString('s-maxage', $cc);
    }

    /**
     * Regression test for a real cache-coherency gap found by the image-level
     * smoke test (docker/test/cache-smoke.sh, T7): Souin's shared-cache mode
     * (bypass_request) only ignores the *request's* no-store/no-cache
     * directives — it still honors the *response's* no-store. Symfony's
     * default "no-cache, private" (what the response would carry if this
     * listener did nothing) is NOT enough: Souin stored and later served a
     * hit anyway via its own default TTL. Explicit no-store is what actually
     * disables caching when the ttl setting is 0.
     */
    public function testPageResponseCarriesNoStoreWhenDisabled(): void
    {
        $this->setHttpCacheTtl(0);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/pages/1', $community->getIdentifier(), $channel->getIdentifier()));

        self::assertResponseIsSuccessful();
        $cc = $response->getHeaders()['cache-control'][0] ?? '';
        self::assertStringContainsString('no-store', $cc);
    }

    public function testPageResponseCarriesCacheHeadersWhenEnabled(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/pages/1', $community->getIdentifier(), $channel->getIdentifier()));

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
        self::assertNotContains('Authorization', $headers['vary'] ?? []);
    }

    public function testConversationPageNeverCarriesCacheHeaders(): void
    {
        $this->setHttpCacheTtl(300);

        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cache-dm-community')->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);

        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        $client = $this->jsonClient($a);
        $client->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => 'hi there'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $client->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier().'/pages/1');

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        $cc = $headers['cache-control'][0] ?? '';
        self::assertStringNotContainsString('s-maxage', $cc);
        self::assertNotContains('X-User-Context-Hash', $headers['vary'] ?? []);
    }

    public function testTtlIsClampedToMediaTokenTtl(): void
    {
        $this->setHttpCacheTtl(999999);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/pages/1', $community->getIdentifier(), $channel->getIdentifier()));

        self::assertStringContainsString('s-maxage=3600', $response->getHeaders()['cache-control'][0]);
    }

    public function testChannelCurrentCarriesCacheHeadersWhenEnabled(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/messages/current', $community->getIdentifier(), $channel->getIdentifier()));

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
        self::assertNotContains('Authorization', $headers['vary'] ?? []);
    }

    public function testPinnedMessagesCarryCacheHeadersWhenEnabled(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/pinned-messages', $community->getIdentifier(), $channel->getIdentifier()));

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
        self::assertNotContains('Authorization', $headers['vary'] ?? []);
    }

    public function testCommunityEmojisCarryCacheHeadersWhenEnabled(): void
    {
        $this->setHttpCacheTtl(300);
        $community = CommunityFactory::new()->withIdentifier('cache-emoji-community')->create();

        $response = $this->jsonClient()->request('GET', sprintf('/api/v1/communities/%s/emojis', $community->getIdentifier()));

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
        self::assertNotContains('Authorization', $headers['vary'] ?? []);
    }

    public function testConversationCurrentNeverCarriesCacheHeaders(): void
    {
        $this->setHttpCacheTtl(300);

        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cache-dm-current-community')->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);

        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        $client = $this->jsonClient($a);
        $client->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => 'hi there'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $client->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages/current');

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        $cc = $headers['cache-control'][0] ?? '';
        self::assertStringNotContainsString('s-maxage', $cc);
        self::assertNotContains('X-User-Context-Hash', $headers['vary'] ?? []);
    }

    public function testAllThreeEmitNoStoreWhenDisabled(): void
    {
        $this->setHttpCacheTtl(0);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $current = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/messages/current', $community->getIdentifier(), $channel->getIdentifier()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $current->getHeaders()['cache-control'][0] ?? '');

        $pinned = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/pinned-messages', $community->getIdentifier(), $channel->getIdentifier()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $pinned->getHeaders()['cache-control'][0] ?? '');

        $emojis = $this->jsonClient()->request('GET', sprintf('/api/v1/communities/%s/emojis', $community->getIdentifier()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $emojis->getHeaders()['cache-control'][0] ?? '');
    }

    public function testThreadRepliesCarryCacheHeadersWhenEnabled(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $rootResponse = $client->request('POST', sprintf('/api/v1/communities/%s/channels/%s/messages', $community->getIdentifier(), $channel->getIdentifier()), [
            'json' => ['text' => 'root message'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $rootId = basename((string) $rootResponse->toArray()['@id']);

        $replyResponse = $client->request('POST', '/api/v1/messages/'.$rootId.'/replies', [
            'json' => ['text' => 'a reply'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $client->request('GET', '/api/v1/messages/'.$rootId.'/thread');

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
        self::assertNotContains('Authorization', $headers['vary'] ?? []);
    }

    public function testMessageItemCarriesCacheHeadersWhenEnabled(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $rootResponse = $client->request('POST', sprintf('/api/v1/communities/%s/channels/%s/messages', $community->getIdentifier(), $channel->getIdentifier()), [
            'json' => ['text' => 'root message'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $rootId = basename((string) $rootResponse->toArray()['@id']);

        $response = $client->request('GET', '/api/v1/messages/'.$rootId);

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
        self::assertNotContains('Authorization', $headers['vary'] ?? []);
    }

    public function testThreadSiblingStillCachesAfterMessageItemRegexpChange(): void
    {
        // Regression for the Caddyfile regexp edit: the `/thread` suffix must
        // stay optional-but-recognized, not accidentally excluded, once the
        // bare message item shape is added alongside it.
        $this->setHttpCacheTtl(300);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $rootResponse = $client->request('POST', sprintf('/api/v1/communities/%s/channels/%s/messages', $community->getIdentifier(), $channel->getIdentifier()), [
            'json' => ['text' => 'root message'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $rootId = basename((string) $rootResponse->toArray()['@id']);

        $replyResponse = $client->request('POST', '/api/v1/messages/'.$rootId.'/replies', [
            'json' => ['text' => 'a reply'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $client->request('GET', '/api/v1/messages/'.$rootId.'/thread');

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
    }

    public function testCachedOpWithQueryStringEmitsNoStore(): void
    {
        // Same fixture as testThreadRepliesCarryCacheHeadersWhenEnabled, but
        // the request carries ?limit=3 — query-string variants are
        // purge-invisible (Souin Surrogate-Key = bare path), so they must
        // never be stored even though the bare-path op is cacheable.
        $this->setHttpCacheTtl(300);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $rootResponse = $client->request('POST', sprintf('/api/v1/communities/%s/channels/%s/messages', $community->getIdentifier(), $channel->getIdentifier()), [
            'json' => ['text' => 'root message'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $rootId = basename((string) $rootResponse->toArray()['@id']);

        $replyResponse = $client->request('POST', '/api/v1/messages/'.$rootId.'/replies', [
            'json' => ['text' => 'a reply'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $client->request('GET', '/api/v1/messages/'.$rootId.'/thread?limit=3');

        self::assertResponseIsSuccessful();
        $cc = $response->getHeaders()['cache-control'][0] ?? '';
        self::assertStringContainsString('no-store', $cc);
        self::assertStringNotContainsString('s-maxage', $cc);
    }

    public function testPresenceSummaryCarriesItsOwnTtl(): void
    {
        $this->setPresenceTtl(15);
        [$client, $community, $channel] = $this->memberClientWithChannelPage();

        $summaryResponse = $client->request('GET', sprintf('/api/v1/communities/%s/presence/summary', $community->getIdentifier()));
        self::assertResponseIsSuccessful();
        $summaryHeaders = $summaryResponse->getHeaders();
        self::assertStringContainsString('s-maxage=15', $summaryHeaders['cache-control'][0]);

        $pageResponse = $client->request('GET', sprintf('/api/v1/communities/%s/channels/%s/pages/1', $community->getIdentifier(), $channel->getIdentifier()));
        self::assertResponseIsSuccessful();
        $cc = $pageResponse->getHeaders()['cache-control'][0] ?? '';
        self::assertStringContainsString('no-store', $cc);
    }

    public function testPresenceSummaryDarkByDefault(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/presence/summary', $community->getIdentifier()));

        self::assertResponseIsSuccessful();
        $cc = $response->getHeaders()['cache-control'][0] ?? '';
        self::assertStringContainsString('no-store', $cc);
    }

    public function testPresenceTtlIsNotMediaClamped(): void
    {
        $this->setPresenceTtl(999999);
        [$client, $community] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/presence/summary', $community->getIdentifier()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('s-maxage=999999', $response->getHeaders()['cache-control'][0]);
    }

    public function testCommunityDetailCarriesCacheHeadersWhenEnabled(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', '/api/v1/communities/'.$community->getIdentifier());

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        self::assertStringContainsString('s-maxage=300', $headers['cache-control'][0]);
        self::assertStringContainsString('public', $headers['cache-control'][0]);
        self::assertContains('X-User-Context-Hash', $headers['vary'] ?? []);
        self::assertNotContains('Authorization', $headers['vary'] ?? []);
    }

    public function testCommunityDetailEmitsNoStoreWhenDisabled(): void
    {
        $this->setHttpCacheTtl(0);
        [$client, $community] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', '/api/v1/communities/'.$community->getIdentifier());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0] ?? '');
    }

    public function testCommunityMembershipNeverCarriesCacheHeaders(): void
    {
        $this->setHttpCacheTtl(300);
        [$client, $community] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', sprintf('/api/v1/communities/%s/membership', $community->getIdentifier()));

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        $cc = $headers['cache-control'][0] ?? '';
        self::assertStringNotContainsString('s-maxage', $cc);
        self::assertNotContains('X-User-Context-Hash', $headers['vary'] ?? []);
    }

    public function testCommunitiesCollectionNeverCarriesCacheHeaders(): void
    {
        $this->setHttpCacheTtl(300);
        [$client] = $this->memberClientWithChannelPage();

        $response = $client->request('GET', '/api/v1/communities');

        self::assertResponseIsSuccessful();
        $headers = $response->getHeaders();
        $cc = $headers['cache-control'][0] ?? '';
        self::assertStringNotContainsString('s-maxage', $cc);
        self::assertNotContains('X-User-Context-Hash', $headers['vary'] ?? []);
    }
}
