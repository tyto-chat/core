<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CacheContextHashTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAnonymousRequestGetsAnonHash(): void
    {
        $response = $this->jsonClient()->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/general/channels/main/pages/1'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('v1:anon', $response->getHeaders()['x-user-context-hash'][0]);
    }

    public function testMemberGetsStandardBucketForPublicCommunity(): void
    {
        $community = CommunityFactory::new()->withIdentifier('hash-pub')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'main'])->create();
        $user = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/hash-pub/channels/main/pages/1'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(sprintf('v1:C%d:standard', $community->getId()), $response->getHeaders()['x-user-context-hash'][0]);
    }

    public function testNonMemberOfPrivateCommunityGetsAnonHash(): void
    {
        $community = CommunityFactory::new()->private()->withIdentifier('hash-priv')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'main'])->create();
        $user = UserFactory::createOne();

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/hash-priv/channels/main/pages/1'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('v1:anon', $response->getHeaders()['x-user-context-hash'][0]);
    }

    public function testCommunityAdminGetsPerUserFingerprintNotStandardBucket(): void
    {
        $community = CommunityFactory::new()->withIdentifier('hash-admin')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'main'])->create();
        $user = UserFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($user, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/hash-admin/channels/main/pages/1'],
        ]);

        self::assertResponseStatusCodeSame(204);
        $bucket = $response->getHeaders()['x-user-context-hash'][0];
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame(sprintf('v1:C%d:standard', $community->getId()), $bucket);
    }

    public function testMemberGetsStandardBucketForThreadUri(): void
    {
        $community = CommunityFactory::new()->withIdentifier('hash-thread')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'main'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $root = MessageFactory::new()->inPage($page)->create();
        $user = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/messages/'.$root->getId().'/thread'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame(sprintf('v1:C%d:standard', $community->getId()), $response->getHeaders()['x-user-context-hash'][0]);
    }

    public function testDmThreadParticipantGetsPerUserFingerprintNotAnon(): void
    {
        $conversation = ConversationFactory::new()->create();
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->create();
        $participant = UserFactory::createOne();

        $response = $this->jsonClient($participant)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/messages/'.$root->getId().'/thread'],
        ]);

        self::assertResponseStatusCodeSame(204);
        $bucket = $response->getHeaders()['x-user-context-hash'][0];
        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
    }

    public function testDmThreadNeverSharesBucketAcrossUsers(): void
    {
        // Regression for the leak: a participant's cached 200 must never land
        // in a bucket a second participant, or a non-participant stranger,
        // could also hit — so every authenticated caller gets their own
        // fingerprint for a conversation thread root, no matter their role.
        $conversation = ConversationFactory::new()->create();
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->create();
        $participantA = UserFactory::createOne();
        $participantB = UserFactory::createOne();
        $stranger = UserFactory::createOne();

        $uri = '/api/v1/messages/'.$root->getId().'/thread';
        $headers = ['X-Forwarded-Uri' => $uri];

        $bucketA = $this->jsonClient($participantA)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];
        $bucketB = $this->jsonClient($participantB)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];
        $bucketStranger = $this->jsonClient($stranger)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];

        self::assertNotSame('v1:anon', $bucketA);
        self::assertNotSame('v1:anon', $bucketB);
        self::assertNotSame('v1:anon', $bucketStranger);
        self::assertNotSame($bucketA, $bucketB);
        self::assertNotSame($bucketA, $bucketStranger);
        self::assertNotSame($bucketB, $bucketStranger);
    }

    public function testAnonymousDmThreadRequestGetsAnonHash(): void
    {
        $conversation = ConversationFactory::new()->create();
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $root = MessageFactory::new()->inPage($page)->create();

        $response = $this->jsonClient()->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/messages/'.$root->getId().'/thread'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('v1:anon', $response->getHeaders()['x-user-context-hash'][0]);
    }

    public function testResponseIsNeverCacheable(): void
    {
        $response = $this->jsonClient()->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/x/channels/y/pages/1'],
        ]);

        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0] ?? '');
    }

    public function testCommunityDetailUriCollapsesMemberAndNonMemberIntoStandardBucket(): void
    {
        $community = CommunityFactory::new()->withIdentifier('hash-detail')->create();
        $member = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $nonMember = UserFactory::createOne();

        $memberResponse = $this->jsonClient($member)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/hash-detail'],
        ]);
        self::assertResponseStatusCodeSame(204);
        $memberBucket = $memberResponse->getHeaders()['x-user-context-hash'][0];

        $nonMemberResponse = $this->jsonClient($nonMember)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/hash-detail'],
        ]);
        self::assertResponseStatusCodeSame(204);
        $nonMemberBucket = $nonMemberResponse->getHeaders()['x-user-context-hash'][0];

        // The collapse: the viewer-agnostic detail payload (task-2 canary) is
        // what makes sharing one bucket across members and non-members safe.
        self::assertSame(sprintf('v1:C%d:standard', $community->getId()), $memberBucket);
        self::assertSame($memberBucket, $nonMemberBucket);
    }

    public function testCommunityDetailUriPrivateNonMemberGetsAnonHash(): void
    {
        CommunityFactory::new()->private()->withIdentifier('hash-detail-priv')->create();
        $user = UserFactory::createOne();

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/communities/hash-detail-priv'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('v1:anon', $response->getHeaders()['x-user-context-hash'][0]);
    }

    public function testPublicMessageBucketIsSharedStandardForPlainMembers(): void
    {
        $community = CommunityFactory::new()->withIdentifier('hash-msg-pub')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'main'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $userA = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($userA, $community);
        $userB = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($userB, $community);

        $uri = '/api/v1/messages/'.$message->getId();
        $headers = ['X-Forwarded-Uri' => $uri];

        $bucketA = $this->jsonClient($userA)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];
        $bucketB = $this->jsonClient($userB)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];

        self::assertSame(sprintf('v1:C%d:standard', $community->getId()), $bucketA);
        self::assertSame($bucketA, $bucketB);
    }

    public function testPrivateChannelMessageBucketsGrantHoldersPerUser(): void
    {
        $community = CommunityFactory::new()->withIdentifier('hash-msg-priv')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $insiderA = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($insiderA, $community);
        ChannelMemberFactory::createForUserAndChannel($insiderA, $channel);

        $insiderB = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($insiderB, $community);
        ChannelMemberFactory::createForUserAndChannel($insiderB, $channel);

        $plainMember = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($plainMember, $community);

        $uri = '/api/v1/messages/'.$message->getId();
        $headers = ['X-Forwarded-Uri' => $uri];

        $bucketInsiderA = $this->jsonClient($insiderA)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];
        $bucketInsiderB = $this->jsonClient($insiderB)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];
        $bucketPlainMember = $this->jsonClient($plainMember)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];

        $standardBucket = sprintf('v1:C%d:standard', $community->getId());

        self::assertNotSame($standardBucket, $bucketInsiderA);
        self::assertNotSame($standardBucket, $bucketInsiderB);
        self::assertNotSame($bucketInsiderA, $bucketInsiderB);
        self::assertSame($standardBucket, $bucketPlainMember);
    }

    public function testDmMessageBucketsPerUser(): void
    {
        $conversation = ConversationFactory::new()->create();
        $page = MessagePageFactory::new()->forConversation($conversation)->create();
        $message = MessageFactory::new()->inPage($page)->create();

        $participantA = UserFactory::createOne();
        $participantB = UserFactory::createOne();
        $stranger = UserFactory::createOne();

        $uri = '/api/v1/messages/'.$message->getId();
        $headers = ['X-Forwarded-Uri' => $uri];

        $bucketA = $this->jsonClient($participantA)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];
        $bucketB = $this->jsonClient($participantB)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];
        $bucketStranger = $this->jsonClient($stranger)->request('GET', '/api/http-cache/context-hash', ['headers' => $headers])->getHeaders()['x-user-context-hash'][0];

        self::assertNotSame('v1:anon', $bucketA);
        self::assertNotSame('v1:anon', $bucketB);
        self::assertNotSame('v1:anon', $bucketStranger);
        self::assertNotSame($bucketA, $bucketB);
        self::assertNotSame($bucketA, $bucketStranger);
        self::assertNotSame($bucketB, $bucketStranger);
    }

    public function testUnknownMessageUuidBucketsAnon(): void
    {
        $user = UserFactory::createOne();

        $response = $this->jsonClient($user)->request('GET', '/api/http-cache/context-hash', [
            'headers' => ['X-Forwarded-Uri' => '/api/v1/messages/11111111-1111-1111-1111-111111111111'],
        ]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('v1:anon', $response->getHeaders()['x-user-context-hash'][0]);
    }
}
