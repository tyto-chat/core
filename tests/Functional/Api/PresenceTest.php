<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Presence\PresenceState;
use App\Service\Presence\GuestPresenceServiceInterface;
use App\Service\Presence\PresenceServiceInterface;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemoryGuestPresenceService;
use App\Tests\Stub\InMemoryPresenceService;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PresenceTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function presenceStub(): InMemoryPresenceService
    {
        $svc = static::getContainer()->get(PresenceServiceInterface::class);
        \assert($svc instanceof InMemoryPresenceService);

        return $svc;
    }

    public function testKernelListenerTouchesPresenceOnAnyAuthedRequest(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/presence?userIds[]='.$user->getId());

        self::assertResponseIsSuccessful();
        $snapshot = $this->presenceStub()->get($user);
        self::assertSame(PresenceState::Online, $snapshot->state);
    }

    public function testBatchEndpointReturnsOfflineForUntouchedUser(): void
    {
        $caller = UserFactory::createOne();
        $other = UserFactory::createOne();

        $response = $this->plainJsonClient($caller)->request(
            'GET',
            '/api/v1/presence?userIds[]='.$other->getId(),
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $byId = [];
        foreach ($data['presences'] as $row) {
            $byId[$row['userId']] = $row['state'];
        }

        self::assertSame('offline', $byId[$other->getId()]);
    }

    public function testManualAwayOverridesLiveSignal(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request(
            'POST',
            '/api/v1/me/presence',
            ['body' => json_encode(['status' => 'away'], JSON_THROW_ON_ERROR)],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(PresenceState::Away, $this->presenceStub()->get($user)->state);
    }

    public function testInvisibleManualStatusCollapsesToOffline(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request(
            'POST',
            '/api/v1/me/presence',
            ['body' => json_encode(['status' => 'invisible'], JSON_THROW_ON_ERROR)],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(PresenceState::Offline, $this->presenceStub()->get($user)->state);
    }

    public function testClearingManualRestoresLiveOnline(): void
    {
        $user = UserFactory::createOne();
        $client = $this->plainJsonClient($user);

        $client->request('POST', '/api/v1/me/presence', ['body' => json_encode(['status' => 'dnd'], JSON_THROW_ON_ERROR)]);
        $client->request('POST', '/api/v1/me/presence', ['body' => json_encode(['status' => null], JSON_THROW_ON_ERROR)]);

        self::assertResponseIsSuccessful();
        self::assertSame(PresenceState::Online, $this->presenceStub()->get($user)->state);
    }

    public function testInvalidStatusValueReturns422(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request(
            'POST',
            '/api/v1/me/presence',
            ['body' => json_encode(['status' => 'fishing'], JSON_THROW_ON_ERROR)],
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testBatchEndpointRequiresAuthentication(): void
    {
        $this->plainJsonClient()->request('GET', '/api/v1/presence?userIds[]=1');

        self::assertResponseStatusCodeSame(401);
    }

    public function testManualStatusRequiresAuthentication(): void
    {
        $this->plainJsonClient()->request(
            'POST',
            '/api/v1/me/presence',
            ['body' => json_encode(['status' => 'away'], JSON_THROW_ON_ERROR)],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testOfflineBeaconFlipsLiveUserToOffline(): void
    {
        $user = UserFactory::createOne();
        $client = $this->plainJsonClient($user);

        $client->request('GET', '/api/v1/presence?userIds[]='.$user->getId());
        self::assertSame(PresenceState::Online, $this->presenceStub()->get($user)->state);

        $client->request('POST', '/api/v1/me/presence/offline');
        self::assertResponseStatusCodeSame(204);
        self::assertSame(PresenceState::Offline, $this->presenceStub()->get($user)->state);
    }

    public function testOfflineBeaconRequiresAuthentication(): void
    {
        $this->plainJsonClient()->request('POST', '/api/v1/me/presence/offline');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCommunitySummaryCountsNonOfflineMembers(): void
    {
        $caller = UserFactory::createOne();
        $member1 = UserFactory::createOne();
        $member2 = UserFactory::createOne();
        $idleMember = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('pres-sum')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        CommunityMemberFactory::createForUserAndCommunity($member1, $community);
        CommunityMemberFactory::createForUserAndCommunity($member2, $community);
        CommunityMemberFactory::createForUserAndCommunity($idleMember, $community);

        $this->presenceStub()->touch($member1);
        $this->presenceStub()->touch($member2);

        $response = $this->plainJsonClient($caller)->request(
            'GET',
            '/api/v1/communities/pres-sum/presence/summary',
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertSame(3, $data['onlineCount']);
    }

    public function testCommunityOnlineListReturnsNonOfflineMembers(): void
    {
        $caller = UserFactory::createOne();
        $onlineMember = UserFactory::createOne();
        $idleMember = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('pres-list')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        CommunityMemberFactory::createForUserAndCommunity($onlineMember, $community);
        CommunityMemberFactory::createForUserAndCommunity($idleMember, $community);

        $this->presenceStub()->touch($onlineMember);

        $response = $this->plainJsonClient($caller)->request(
            'GET',
            '/api/v1/communities/pres-list/presence/online',
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        $userIds = array_column($data['users'], 'userId');

        self::assertContains($caller->getId(), $userIds);
        self::assertContains($onlineMember->getId(), $userIds);
        self::assertNotContains($idleMember->getId(), $userIds);
    }

    public function testPrivateCommunityPresenceRejectsNonMember(): void
    {
        $outsider = UserFactory::createOne();
        $member = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('pres-priv')->private()->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->plainJsonClient($outsider)->request(
            'GET',
            '/api/v1/communities/pres-priv/presence/summary',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testPublicCommunityPresenceVisibleToOutsider(): void
    {
        $outsider = UserFactory::createOne();
        $member = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('pres-pub')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->plainJsonClient($outsider)->request(
            'GET',
            '/api/v1/communities/pres-pub/presence/summary',
        );

        self::assertResponseIsSuccessful();
    }

    public function testPublicCommunitySummaryVisibleToAnonymous(): void
    {
        $member = UserFactory::createOne();

        $community = CommunityFactory::new()->withIdentifier('pres-anon-pub')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        $this->presenceStub()->touch($member);

        $response = $this->plainJsonClient()->request(
            'GET',
            '/api/v1/communities/pres-anon-pub/presence/summary',
        );

        self::assertResponseIsSuccessful();
        self::assertSame(1, $response->toArray()['onlineCount']);
    }

    public function testPrivateCommunitySummaryRejectsAnonymous(): void
    {
        CommunityFactory::new()->withIdentifier('pres-anon-priv')->private()->create();

        $this->plainJsonClient()->request(
            'GET',
            '/api/v1/communities/pres-anon-priv/presence/summary',
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testPublicCommunitySummaryIncludesGuestCount(): void
    {
        CommunityFactory::new()->withIdentifier('guests-pub')->create();
        $guestStub = static::getContainer()->get(GuestPresenceServiceInterface::class);
        \assert($guestStub instanceof InMemoryGuestPresenceService);
        $community = static::getContainer()->get(\App\Repository\CommunityRepository::class)
            ->findOneBy(['identifier' => 'guests-pub']);
        self::assertNotNull($community);
        $guestStub->touch($community, 'visitor-a');
        $guestStub->touch($community, 'visitor-b');

        $response = $this->plainJsonClient(UserFactory::createOne())->request(
            'GET',
            '/api/v1/communities/guests-pub/presence/summary',
        );

        self::assertResponseIsSuccessful();
        self::assertSame(2, $response->toArray()['guestsOnline']);
    }

    public function testPrivateCommunitySummaryReportsZeroGuests(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('guests-priv')->private()->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $response = $this->plainJsonClient($member)->request(
            'GET',
            '/api/v1/communities/guests-priv/presence/summary',
        );

        self::assertResponseIsSuccessful();
        self::assertSame(0, $response->toArray()['guestsOnline']);
    }
}
