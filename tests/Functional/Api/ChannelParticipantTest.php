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

class ChannelParticipantTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testChannelMemberCanListParticipants(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cp-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cp-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/cp-c/channels/cp-ch/participants');

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertArrayHasKey('hydra:member', $data);
    }

    public function testNonChannelMemberCanListParticipantsOfPublicChannel(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cp-priv')->create();
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cp-secret'])->create();

        $this->jsonClient($outsider)->request('GET', '/api/v1/communities/cp-priv/channels/cp-secret/participants');

        self::assertResponseIsSuccessful();
    }

    public function testCommunityNonMemberCannotListParticipantsOfPublicChannel(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cp-outsider')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cp-outsider-ch'])->create();

        $this->jsonClient($outsider)->request('GET', '/api/v1/communities/cp-outsider/channels/cp-outsider-ch/participants');

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonChannelMemberCannotListParticipantsOfPrivateChannel(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cp-priv2')->create();
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'cp-secret-priv'])->create();

        $this->jsonClient($outsider)->request('GET', '/api/v1/communities/cp-priv2/channels/cp-secret-priv/participants');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousCannotListParticipants(): void
    {
        $community = CommunityFactory::new()->withIdentifier('cp-anon')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'cp-ch-anon'])->create();

        $this->jsonClient()->request('GET', '/api/v1/communities/cp-anon/channels/cp-ch-anon/participants');

        self::assertResponseStatusCodeSame(401);
    }
}
