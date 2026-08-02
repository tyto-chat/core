<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MessagePageTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{\App\Entity\User, \App\Entity\Community, \App\Entity\Channel} */
    private function setupChannel(): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('page-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'page-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        return [$user, $community, $channel];
    }

    public function testListReturnsAllPagesForChannel(): void
    {
        [$user, , $channel] = $this->setupChannel();
        MessagePageFactory::new()->forChannel($channel)->many(3)->create();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/page-c/channels/page-ch/pages');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertCount(3, $data['hydra:member']);
    }

    public function testListReturnsCollectionForAnonymousOnPublicChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('anon-pages')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/anon-pages/channels/ch/pages');

        self::assertResponseIsSuccessful();
    }

    public function testListReturnsUnauthorizedForAnonymousOnPrivateChannel(): void
    {
        $community = CommunityFactory::new()->withIdentifier('anon-pages-priv')->create();
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'priv-ch'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/anon-pages-priv/channels/priv-ch/pages');

        // Symfony promotes anonymous deny → 401 (login might resolve it)
        self::assertResponseStatusCodeSame(401);
    }

    public function testGetPageIncludesMessageCount(): void
    {
        [$user, , $channel] = $this->setupChannel();

        $page1 = MessagePageFactory::new()->forChannel($channel)->create();
        $page2 = MessagePageFactory::new()->forChannel($channel)->create();
        MessagePageFactory::new()->forChannel($channel)->create();

        MessageFactory::new()->inPage($page2)->many(5)->create();

        $response = $this->jsonClient($user)->request(
            'GET',
            '/api/v1/communities/page-c/channels/page-ch/pages/'.$page2->getPageNumber()
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(5, $data['messageCount']);
        self::assertSame($page2->getPageNumber(), $data['pageNumber']);
    }

    public function testGetFirstPageByPageNumber(): void
    {
        [$user, , $channel] = $this->setupChannel();
        $page = MessagePageFactory::new()->forChannel($channel)->create();

        $this->jsonClient($user)->request(
            'GET',
            '/api/v1/communities/page-c/channels/page-ch/pages/'.$page->getPageNumber()
        );

        self::assertResponseIsSuccessful();
    }

    public function testGetPageReturns404ForUnknownPageNumber(): void
    {
        [$user] = $this->setupChannel();

        $this->jsonClient($user)->request('GET', '/api/v1/communities/page-c/channels/page-ch/pages/99999');

        self::assertResponseStatusCodeSame(404);
    }
}
