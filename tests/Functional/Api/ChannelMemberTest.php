<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelMemberTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testChannelMemberCanListMembers(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chm-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($other, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'chm-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        ChannelMemberFactory::createForUserAndChannel($other, $channel);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/chm-c/channels/chm-ch/members');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertCount(2, $data['hydra:member']);
    }

    public function testNonChannelMemberCannotListMembers(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chm-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'chm-secret'])->create();

        $this->jsonClient($outsider)->request('GET', '/api/v1/communities/chm-priv/channels/chm-secret/members');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotListChannelMembers(): void
    {
        $community = CommunityFactory::new()->withIdentifier('chm-anon')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'chm-ch-anon'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/chm-anon/channels/chm-ch-anon/members');

        self::assertResponseStatusCodeSame(401);
    }
}
