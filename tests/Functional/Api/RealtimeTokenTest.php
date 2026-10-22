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

    public function testTokenWithoutScopeCoversOnlyTheCallersOwnTopics(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-none')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'gen'])->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $topics = $this->subscribeTopicsFor($user);

        self::assertEqualsCanonicalizing([
            'u/'.$user->getId().'/n',
            'u/'.$user->getId().'/e',
            'u/'.$user->getId().'/d',
            'u/{userId}/p',
        ], $topics);
    }

    public function testCommunityScopeCoversThatCommunityOnly(): void
    {
        $user = UserFactory::createOne();
        $active = CommunityFactory::new()->withIdentifier('rt-active')->create();
        $channel = ChannelFactory::new()->inCommunity($active)->with(['identifier' => 'gen'])->create();
        $other = CommunityFactory::new()->withIdentifier('rt-other')->create();
        $otherChannel = ChannelFactory::new()->inCommunity($other)->with(['identifier' => 'gen'])->create();

        $topics = $this->subscribeTopicsFor($user, ['community' => 'rt-active']);

        self::assertContains('k/'.$active->getId(), $topics);
        self::assertContains('k/'.$active->getId().'/a', $topics);
        self::assertContains('k/'.$active->getId().'/e', $topics);
        self::assertContains('c/'.$channel->getId(), $topics);
        self::assertContains('c/'.$channel->getId().'/t', $topics);
        self::assertNotContains('k/'.$other->getId(), $topics);
        self::assertNotContains('k/'.$other->getId().'/a', $topics);
        self::assertNotContains('k/'.$other->getId().'/e', $topics);
        self::assertNotContains('c/'.$otherChannel->getId(), $topics);
        self::assertNotContains('c/'.$otherChannel->getId().'/t', $topics);
    }

    public function testCommunityScopeGrantsVoiceParticipantsForAudioChannels(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-voice')->create();
        $lounge = ChannelFactory::new()->inCommunity($community)->audio()->with(['identifier' => 'lounge'])->create();

        $topics = $this->subscribeTopicsFor($user, ['community' => 'rt-voice']);

        self::assertContains('c/'.$lounge->getId().'/p', $topics);
    }

    public function testPrivateCommunityIsLeftOutForNonMembers(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('rt-priv')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'secret-general'])->create();

        $topics = $this->subscribeTopicsFor($outsider, ['community' => 'rt-priv']);

        self::assertNotContains('k/'.$community->getId(), $topics);
        self::assertNotContains('c/'.$channel->getId(), $topics);
        self::assertCount(4, $topics);
    }

    public function testPrivateCommunityIsCoveredForMembers(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('rt-priv-ok')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'members-general'])->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $topics = $this->subscribeTopicsFor($member, ['community' => 'rt-priv-ok']);

        self::assertContains('c/'.$channel->getId(), $topics);
    }

    public function testPrivateChannelIsLeftOutForMembersWithoutAccess(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-pch')->create();
        $staff = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'staff'])->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $topics = $this->subscribeTopicsFor($member, ['community' => 'rt-pch']);

        self::assertContains('k/'.$community->getId(), $topics);
        self::assertNotContains('c/'.$staff->getId(), $topics);
    }

    public function testUnknownCommunityIsIgnored(): void
    {
        $user = UserFactory::createOne();

        $topics = $this->subscribeTopicsFor($user, ['community' => 'does-not-exist']);

        self::assertCount(4, $topics);
    }

    public function testNoWildcardOrTemplateTopicBeyondPresenceIsGranted(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-glob')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'gen'])->create();

        $templated = array_values(array_filter(
            $this->subscribeTopicsFor($user, ['community' => 'rt-glob']),
            static fn (string $topic): bool => str_contains($topic, '{') || str_contains($topic, '*'),
        ));

        self::assertSame(['u/{userId}/p'], $templated);
    }

    public function testTokenTopicsContainNoIdentifiers(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rt-noident')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'noident-general'])->create();
        $audio = ChannelFactory::new()->inCommunity($community)->audio()->with(['identifier' => 'noident-lounge'])->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $conversation = ConversationFactory::new()->withParticipants([$user, $other])->create();
        ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        ConversationMemberFactory::createForUserAndConversation($other, $conversation);

        $topics = $this->subscribeTopicsFor($user, [
            'community' => 'rt-noident',
            'conversation' => $conversation->getIdentifier(),
        ]);

        self::assertContains('c/'.$channel->getId(), $topics);
        self::assertContains('c/'.$audio->getId().'/p', $topics);
        self::assertContains('m/'.$conversation->getId(), $topics);
        foreach ($topics as $topic) {
            self::assertStringNotContainsString($community->getIdentifier(), $topic);
            self::assertStringNotContainsString($channel->getIdentifier(), $topic);
            self::assertStringNotContainsString($audio->getIdentifier(), $topic);
            self::assertStringNotContainsString($conversation->getIdentifier(), $topic);
            self::assertStringNotContainsString('/api/', $topic);
        }
    }

    public function testConversationScopeCoversItForParticipantsOnly(): void
    {
        $alice = UserFactory::createOne();
        $stranger = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);
        $scope = ['conversation' => $conversation->getIdentifier()];
        $topic = 'm/'.$conversation->getId();

        $aliceTopics = $this->subscribeTopicsFor($alice, $scope);
        self::assertContains($topic, $aliceTopics);
        self::assertContains($topic.'/t', $aliceTopics);

        $strangerTopics = $this->subscribeTopicsFor($stranger, $scope);
        self::assertNotContains($topic, $strangerTopics);
        self::assertNotContains($topic.'/t', $strangerTopics);
    }

    public function testConversationIsLeftOutWhenNotRequested(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $conversation = ConversationFactory::new()->withParticipants([$alice, $bob])->create();
        ConversationMemberFactory::createForUserAndConversation($alice, $conversation);
        ConversationMemberFactory::createForUserAndConversation($bob, $conversation);

        self::assertNotContains('m/'.$conversation->getId(), $this->subscribeTopicsFor($alice));
    }

    /**
     * Fetch a realtime token for the user and return its Mercure subscribe topics.
     *
     * @param array<string, string> $scope
     *
     * @return string[]
     */
    private function subscribeTopicsFor(User $user, array $scope = []): array
    {
        $response = $this->jsonClient($user)->request('GET', '/api/v1/realtime/token', ['query' => $scope]);
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
