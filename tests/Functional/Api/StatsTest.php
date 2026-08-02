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

class StatsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAnonymousCannotAccessStats(): void
    {
        $this->plainJsonClient()->request('GET', '/api/v1/stats');

        self::assertResponseStatusCodeSame(401);
    }

    public function testRegularUserCannotAccessStats(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/stats');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanAccessStats(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('GET', '/api/v1/stats');

        self::assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('system', $data);
        self::assertArrayHasKey('communities', $data);
        self::assertIsNumeric($data['system']['memoryTotal']);
        self::assertIsNumeric($data['system']['diskTotal']);
    }

    public function testStatsIncludeCommunityAggregates(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member1 = UserFactory::createOne();
        $member2 = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('stats-test')->create();
        CommunityMemberFactory::createForUserAndCommunity($member1, $community);
        CommunityMemberFactory::createForUserAndCommunity($member2, $community);

        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($member1)->create();
        MessageFactory::new()->inPage($page)->byUser($member2)->create();

        $this->plainJsonClient($admin)->request('GET', '/api/v1/stats');

        self::assertResponseStatusCodeSame(200);

        $data = json_decode((string) $this->getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $communityStats = $data['communities'][0] ?? null;

        self::assertNotNull($communityStats);
        self::assertSame('stats-test', $communityStats['identifier']);
        self::assertSame(1, $communityStats['channelCount']);
        self::assertSame(2, $communityStats['memberCount']);
        self::assertSame(2, $communityStats['messageCount']);
    }
}
