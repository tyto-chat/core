<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\RecordingMercureHub;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class UserBlockTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testBlockListUnblockRoundTrip(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $client = $this->plainJsonClient($a);

        $created = $client->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertArrayHasKey('avatarUrl', $created);
        self::assertArrayNotHasKey('avatar', $created);

        $list = $client->request('GET', '/api/v1/me/blocks')->toArray();
        self::assertCount(1, $list['items']);
        self::assertSame($b->getId(), $list['items'][0]['userId']);
        self::assertTrue($list['items'][0]['inEffect']);

        $client->request('DELETE', '/api/v1/me/blocks/'.$b->getId());
        self::assertResponseStatusCodeSame(204);

        self::assertSame([], $client->request('GET', '/api/v1/me/blocks')->toArray()['items']);
    }

    public function testBlockIsIdempotent(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $client = $this->plainJsonClient($a);

        $first = $client->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]])->toArray();
        $second = $client->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]])->toArray();

        self::assertSame($first['blockedAt'], $second['blockedAt']);
    }

    public function testStatusReportsBlockableFalseForStaff(): void
    {
        $a = UserFactory::createOne();
        $mod = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $status = $this->plainJsonClient($a)->request('GET', '/api/v1/me/blocks/'.$mod->getId())->toArray();

        self::assertFalse($status['blocked']);
        self::assertFalse($status['blockable']);
    }

    public function testStatusOfBlockedModeratorIsNotInEffect(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $client = $this->plainJsonClient($a);
        $client->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]]);

        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createModeratorForCommunity($b, $community);

        $status = $client->request('GET', '/api/v1/me/blocks/'.$b->getId())->toArray();
        self::assertTrue($status['blocked']);
        self::assertFalse($status['inEffect']);

        $list = $client->request('GET', '/api/v1/me/blocks')->toArray();
        self::assertFalse($list['items'][0]['inEffect']);
    }

    public function testBlockStaffReturns422WithMessage(): void
    {
        $a = UserFactory::createOne();
        $mod = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $response = $this->plainJsonClient($a)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $mod->getId()]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame("You can't block administrators or moderators.", $response->toArray(false)['error']);
    }

    public function testBlockSelfReturns422(): void
    {
        $a = UserFactory::createOne();

        $this->plainJsonClient($a)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $a->getId()]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testBlockUnknownUserReturns404(): void
    {
        $a = UserFactory::createOne();

        $this->plainJsonClient($a)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => 99999999]]);
        self::assertResponseStatusCodeSame(404);

        $this->plainJsonClient($a)->request('GET', '/api/v1/me/blocks/99999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousGets401(): void
    {
        $this->plainJsonClient()->request('GET', '/api/v1/me/blocks');

        self::assertResponseStatusCodeSame(401);
    }

    public function testBlockPublishesUserEventToBlockerOnly(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        RecordingMercureHub::reset();

        $this->plainJsonClient($a)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]]);
        self::assertResponseStatusCodeSame(201);

        $forBlocker = [];
        foreach (RecordingMercureHub::updates() as $update) {
            foreach ($update->getTopics() as $topic) {
                self::assertStringNotContainsString('u/'.$b->getId().'/', $topic);
                if ('u/'.$a->getId().'/e' === $topic) {
                    $forBlocker[] = json_decode($update->getData(), true);
                }
            }
        }

        self::assertCount(1, $forBlocker);
        self::assertSame('blocks.changed', $forBlocker[0]['event']);
        self::assertSame($b->getId(), $forBlocker[0]['userId']);
    }
}
