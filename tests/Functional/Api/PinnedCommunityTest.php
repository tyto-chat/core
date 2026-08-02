<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityInviteFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PinnedCommunityTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testEmptyListForNewUser(): void
    {
        $user = UserFactory::createOne();

        $response = $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities');

        self::assertResponseIsSuccessful();
        self::assertSame([], $response->toArray()['hydra:member']);
    }

    public function testAnonymousCannotListPinned(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/me/pinned-communities');

        self::assertResponseStatusCodeSame(401);
    }

    public function testPinAddsCommunityToTheList(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('to-pin')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities', [
            'json' => ['communityId' => $community->getId()],
        ]);
        self::assertResponseStatusCodeSame(201);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities');
        $items = $response->toArray()['hydra:member'];
        self::assertCount(1, $items);
        self::assertSame('to-pin', $items[0]['community']['identifier']);
        self::assertSame(0, $items[0]['position']);
    }

    public function testPinIsIdempotent(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('idempotent')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities', [
            'json' => ['communityId' => $community->getId()],
        ]);
        $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities', [
            'json' => ['communityId' => $community->getId()],
        ]);

        $items = $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member'];
        self::assertCount(1, $items);
    }

    public function testUnpinRemovesFromList(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('to-unpin')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities', [
            'json' => ['communityId' => $community->getId()],
        ]);
        $this->jsonClient($user)
            ->request('DELETE', '/api/v1/me/pinned-communities/'.$community->getId());
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member']);
    }

    public function testCannotPinPrivateCommunityWithoutAccess(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('hidden')->create();

        $this->jsonClient($outsider)->request('POST', '/api/v1/me/pinned-communities', [
            'json' => ['communityId' => $community->getId()],
        ]);

        // CommunityService::get -> CommunityVoter::VIEW denies non-member.
        self::assertResponseStatusCodeSame(404);
    }

    public function testReorderAcceptsExactSet(): void
    {
        $user = UserFactory::createOne();
        $a = CommunityFactory::new()->withIdentifier('a')->create();
        $b = CommunityFactory::new()->withIdentifier('b')->create();
        $c = CommunityFactory::new()->withIdentifier('c')->create();
        foreach ([$a, $b, $c] as $community) {
            $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities', [
                'json' => ['communityId' => $community->getId()],
            ]);
        }

        $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities/order', [
            'json' => ['communityIds' => [$c->getId(), $a->getId(), $b->getId()]],
        ]);

        // Reorder returns 204; clients refetch the collection.
        self::assertResponseStatusCodeSame(204);
        $items = $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member'];
        self::assertSame(['c', 'a', 'b'], array_map(static fn (array $i) => $i['community']['identifier'], $items));
    }

    public function testReorderWithMismatchedSetReturns422(): void
    {
        $user = UserFactory::createOne();
        $a = CommunityFactory::new()->withIdentifier('only-a')->create();
        $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities', [
            'json' => ['communityId' => $a->getId()],
        ]);

        $this->jsonClient($user)->request('POST', '/api/v1/me/pinned-communities/order', [
            'json' => ['communityIds' => [$a->getId(), 9999]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testJoinAutoPinsTheCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('autopin-join')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/autopin-join/members');

        $items = $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member'];
        self::assertCount(1, $items);
        self::assertSame('autopin-join', $items[0]['community']['identifier']);
    }

    public function testInviteAcceptAutoPinsTheCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('autopin-invite')->create();
        $invite = CommunityInviteFactory::createOne(['community' => $community]);

        $this->jsonClient($user)->request('POST', '/api/v1/invites/'.$invite->getToken().'/accept');

        $items = $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member'];
        self::assertCount(1, $items);
        self::assertSame('autopin-invite', $items[0]['community']['identifier']);
    }

    public function testAddMemberAutoPinsForTarget(): void
    {
        $admin = UserFactory::createOne();
        $newcomer = UserFactory::createOne();
        $community = CommunityFactory::new()->private()->withIdentifier('autopin-add')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/autopin-add/members/add', [
            'json' => ['userId' => $newcomer->getId()],
        ]);

        $items = $this->jsonClient($newcomer)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member'];
        self::assertCount(1, $items);
        self::assertSame('autopin-add', $items[0]['community']['identifier']);
    }

    public function testLeavingUnpinsTheCommunity(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('unpin-leave')->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/unpin-leave/members');
        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/unpin-leave/members');
        self::assertResponseStatusCodeSame(204);

        $items = $this->jsonClient($user)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member'];
        self::assertCount(0, $items, 'leaving a community must remove its rail pin — a stale pin dead-links to a 403');
    }

    public function testBanKickUnpinsForTheTarget(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('unpin-kick')->create();

        $this->jsonClient($member)->request('POST', '/api/v1/communities/unpin-kick/members');

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/unpin-kick/moderation', ['json' => [
            'targetUserId' => $member->getId(),
            'type' => 'ban',
            'reason' => 'Bye',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $items = $this->jsonClient($member)->request('GET', '/api/v1/me/pinned-communities')->toArray()['hydra:member'];
        self::assertCount(0, $items, 'a ban (kick) must remove the rail pin for the target');
    }
}
