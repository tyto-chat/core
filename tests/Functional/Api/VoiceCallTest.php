<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class VoiceCallTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testMemberGetsRoomToken(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('voice-com')->create();
        ChannelFactory::new()->audio()->inCommunity($community)->with(['identifier' => 'voice-ch'])->create();

        $response = $this->jsonClient($user)->request(
            'POST',
            '/api/v1/communities/voice-com/channels/voice-ch/call/token',
        );

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertArrayHasKey('token', $body);
        self::assertNotSame('', $body['token']);
        self::assertArrayHasKey('url', $body);
    }

    public function testTokenOnNonAudioChannelReturns422(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('voice-text-com')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'text-ch'])->create();

        $this->jsonClient($user)->request(
            'POST',
            '/api/v1/communities/voice-text-com/channels/text-ch/call/token',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testLeaveReturns204(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('voice-leave-com')->create();
        ChannelFactory::new()->audio()->inCommunity($community)->with(['identifier' => 'leave-ch'])->create();
        $client = $this->jsonClient($user);

        $client->request('POST', '/api/v1/communities/voice-leave-com/channels/leave-ch/call/token');
        $client->request('DELETE', '/api/v1/communities/voice-leave-com/channels/leave-ch/call');

        self::assertResponseStatusCodeSame(204);
    }

    public function testNonMemberCannotGetTokenForPrivateAudioChannel(): void
    {
        // Community member, but NOT a member of the private audio channel:
        // joinAudioChannelAsCurrentUser enforces CHANNEL_VIEW → denied.
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('voice-priv-com')->create();
        CommunityMemberFactory::createForUserAndCommunity($outsider, $community);
        ChannelFactory::new()->audio()->inCommunity($community)->private()->with(['identifier' => 'voice-priv-ch'])->create();

        $this->jsonClient($outsider)->request(
            'POST',
            '/api/v1/communities/voice-priv-com/channels/voice-priv-ch/call/token',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousCannotGetRoomToken(): void
    {
        $community = CommunityFactory::new()->withIdentifier('voice-anon-com')->create();
        ChannelFactory::new()->audio()->inCommunity($community)->private()->with(['identifier' => 'voice-anon-ch'])->create();

        $this->jsonClient()->request(
            'POST',
            '/api/v1/communities/voice-anon-com/channels/voice-anon-ch/call/token',
        );

        self::assertResponseStatusCodeSame(401);
    }
}
