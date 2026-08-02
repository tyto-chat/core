<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Verifies the anonymous browsing policy:
 *   - public communities and their public channels are readable without authentication
 *   - private communities and private channels are not accessible
 *   - all write / mutating operations are blocked (401)
 */
class AnonymousBrowsingTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAnonymousCanReadPublicCommunity(): void
    {
        CommunityFactory::new()->withIdentifier('pub-com')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/pub-com');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['identifier' => 'pub-com']);
    }

    public function testAnonymousCannotReadPrivateCommunity(): void
    {
        CommunityFactory::new()->private()->withIdentifier('priv-com')->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/priv-com');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCanReadPublicChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('pub-ch-com')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'pub-ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/pub-ch-com/channels/pub-ch');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['identifier' => 'pub-ch']);
    }

    public function testAnonymousCannotReadPrivateChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('priv-ch-com')->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'priv-ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/priv-ch-com/channels/priv-ch');

        // Symfony promotes anonymous deny → 401 (login might resolve it)
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotReadPublicChannelInPrivateCommunity(): void
    {
        $community = CommunityFactory::new()->private()->withIdentifier('priv-com-pub-ch-anon')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'pub-ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/priv-com-pub-ch-anon/channels/pub-ch');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCanListPagesOfPublicChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('pages-com')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'pages-ch'])->create();
        MessagePageFactory::new()->forChannel($channel)->many(2)->create();

        $response = $this->jsonClient()->request('GET', '/api/v1/communities/pages-com/channels/pages-ch/pages');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $response->toArray()['hydra:member']);
    }

    public function testAnonymousCanReadPageOfPublicChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('page-detail-com')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'page-detail-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->many(3)->create();

        $this->jsonClient()->request(
            'GET',
            '/api/v1/communities/page-detail-com/channels/page-detail-ch/pages/'.$page->getPageNumber()
        );

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousCannotListPagesOfPrivateChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('priv-pages-com')->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'priv-pages-ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/priv-pages-com/channels/priv-pages-ch/pages');

        // Symfony promotes anonymous deny → 401 (login might resolve it)
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotReadCurrentMessages(): void
    {
        $community = CommunityFactory::new()->withIdentifier('cur-msg-com')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cur-msg-ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/cur-msg-com/channels/cur-msg-ch/messages/current');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCanGetPublicRealtimeTokenWhenPublicChannelsExist(): void
    {
        $community = CommunityFactory::new()->withIdentifier('realtime-com')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'realtime-ch'])->create();

        $response = $this->jsonClient()->request('GET', '/api/v1/realtime/public-token');

        self::assertResponseIsSuccessful();
        $data = $response->toArray(false);
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('expiresAt', $data);
        self::assertNotEmpty($data['token']);
    }

    public function testPublicRealtimeTokenIsNullWhenNoPublicChannelsExist(): void
    {
        // Only a private community with no public channels
        $community = CommunityFactory::new()->private()->withIdentifier('no-pub-com')->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'no-pub-ch'])->create();

        $response = $this->jsonClient()->request('GET', '/api/v1/realtime/public-token');

        self::assertResponseIsSuccessful();
        $data = $response->toArray(false);
        self::assertNull($data['token']);
        self::assertNull($data['expiresAt']);
    }

    public function testPublicRealtimeTokenExcludesPublicChannelsInPrivateCommunity(): void
    {
        // A non-private channel inside a private community must not leak to anonymous.
        $community = CommunityFactory::new()->private()->withIdentifier('priv-pub-token-com')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'pub-but-priv-com'])->create();

        $response = $this->jsonClient()->request('GET', '/api/v1/realtime/public-token');

        self::assertResponseIsSuccessful();
        self::assertNull($response->toArray(false)['token']);
    }

    public function testAnonymousCannotPostMessage(): void
    {
        $community = CommunityFactory::new()->withIdentifier('write-com')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'write-ch'])->create();

        $this->jsonClient()->request('POST', '/api/v1/communities/write-com/channels/write-ch/messages', [
            'json' => ['text' => 'Hello'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotAddReaction(): void
    {
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('react-anon-com')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'react-anon-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient()->request('POST', '/api/v1/messages/'.$message->getId().'/reactions', [
            'json' => ['emoji' => '👍'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotEditMessage(): void
    {
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('edit-anon-com')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'edit-anon-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient()->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'Injected'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotDeleteMessage(): void
    {
        $author = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('del-anon-com')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'del-anon-ch'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($author)->create();

        $this->jsonClient()->request('DELETE', '/api/v1/messages/'.$message->getId());

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotCreateCommunity(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/communities', ['json' => [
            'name' => 'Injected',
            'identifier' => 'injected',
        ]]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotJoinCommunity(): void
    {
        CommunityFactory::new()->withIdentifier('join-anon-com')->create();

        $this->jsonClient()->request('POST', '/api/v1/communities/join-anon-com/members');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotCreateChannel(): void
    {
        CommunityFactory::new()->withIdentifier('ch-create-com')->create();

        $this->jsonClient()->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Injected',
            'identifier' => 'injected',
            'type' => 'text',
            'community' => '/api/v1/communities/ch-create-com',
        ]]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotGetVoiceToken(): void
    {
        $community = CommunityFactory::new()->withIdentifier('voice-anon-com')->create();
        ChannelFactory::new()->audio()->inCommunity($community)->with(['identifier' => 'voice-anon-ch'])->create();

        $this->jsonClient()->request(
            'POST',
            '/api/v1/communities/voice-anon-com/channels/voice-anon-ch/call/token'
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotGetAuthenticatedRealtimeToken(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/realtime/token');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnonymousCannotUpdateProfile(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient()->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
