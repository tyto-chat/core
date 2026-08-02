<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class RealtimeTokenTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAuthenticatedUserReceivesRealtimeToken(): void
    {
        $user = UserFactory::createOne();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/realtime/token');

        self::assertResponseIsSuccessful();
        $data = $response->toArray(false);
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('expiresAt', $data);
        self::assertNotEmpty($data['token']);
    }

    public function testAnonymousCannotGetRealtimeToken(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/realtime/token');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPublicCommunityChannelIncludedForAnyUser(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-public')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'open'])->create();

        $topics = $this->subscribeTopicsFor($user);

        self::assertContains('/api/communities/rt-public/channels/open', $topics);
    }

    public function testPrivateCommunityChannelExcludedFromNonMemberToken(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('rt-priv')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'secret-general'])->create();

        $topics = $this->subscribeTopicsFor($outsider);

        self::assertNotContains('/api/communities/rt-priv/channels/secret-general', $topics);
    }

    public function testPrivateCommunityChannelIncludedForMemberToken(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('rt-priv-ok')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'members-general'])->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $topics = $this->subscribeTopicsFor($member);

        self::assertContains('/api/communities/rt-priv-ok/channels/members-general', $topics);
    }

    public function testNoGlobalThreadTemplateIsGranted(): void
    {
        $user = UserFactory::createOne();

        self::assertNotContains('/api/messages/{messageId}/thread', $this->subscribeTopicsFor($user));
    }

    public function testChannelThreadTemplateScopedToViewableChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-thr')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'gen'])->create();

        $topics = $this->subscribeTopicsFor($user);

        self::assertContains('/api/communities/rt-thr/channels/gen/messages/{messageId}/thread', $topics);
    }

    public function testDmThreadTemplateGrantedOnlyToParticipants(): void
    {
        $alice = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);

        $threadTemplate = '/api/conversations/'.$conversation->getIdentifier().'/messages/{messageId}/thread';

        self::assertContains($threadTemplate, $this->subscribeTopicsFor($alice));
        self::assertNotContains($threadTemplate, $this->subscribeTopicsFor($stranger));
    }

    public function testTokenSubscribesToCommunityIriAndUserEvents(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-com')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $topics = $this->subscribeTopicsFor($user);

        self::assertContains('/api/communities/rt-com', $topics);
        self::assertContains('/api/users/'.$user->getId().'/events', $topics);
    }

    /**
     * Fetch a realtime token for the user and return its Mercure subscribe topics.
     *
     * @return string[]
     */
    private function subscribeTopicsFor(User $user): array
    {
        $response = $this->jsonClient($user)->request('GET', '/api/v1/realtime/token');
        self::assertResponseIsSuccessful();

        $jwt = $response->toArray(false)['token'];
        $segments = explode('.', $jwt);
        self::assertCount(3, $segments, 'Mercure token is not a JWT');

        $payload = json_decode(
            (string) base64_decode(strtr($segments[1], '-_', '+/'), true),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $payload['mercure']['subscribe'] ?? [];
    }
}
