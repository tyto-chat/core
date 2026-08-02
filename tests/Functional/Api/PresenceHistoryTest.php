<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\PresenceSample;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PresenceHistoryTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function seedSamples(string $identifier): \App\Entity\Community
    {
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->persist(new PresenceSample($community, 4, 1, new \DateTimeImmutable('-2 hours')));
        $em->persist(new PresenceSample($community, 6, 2, new \DateTimeImmutable('-1 hour')));
        $em->persist(new PresenceSample($community, 9, 9, new \DateTimeImmutable('-10 days')));
        $em->flush();

        return $community;
    }

    public function testGlobalAdminSeesHistoryWindow(): void
    {
        $this->seedSamples('hist-admin');
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request(
            'GET',
            '/api/v1/communities/hist-admin/presence/history?days=7',
        );

        self::assertResponseIsSuccessful();
        $samples = $response->toArray()['samples'];
        self::assertCount(2, $samples);
        self::assertSame(4, $samples[0]['membersOnline']);
        self::assertSame(2, $samples[1]['guestsOnline']);
        self::assertArrayHasKey('sampledAt', $samples[0]);
    }

    public function testCommunityAdminSeesHistory(): void
    {
        $community = $this->seedSamples('hist-cadmin');
        $owner = UserFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($owner, $community);

        $this->plainJsonClient($owner)->request('GET', '/api/v1/communities/hist-cadmin/presence/history');

        self::assertResponseIsSuccessful();
    }

    public function testPlainMemberGets404(): void
    {
        $community = $this->seedSamples('hist-member');
        $member = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->plainJsonClient($member)->request('GET', '/api/v1/communities/hist-member/presence/history');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousGets401(): void
    {
        $this->seedSamples('hist-anon');

        $this->plainJsonClient()->request('GET', '/api/v1/communities/hist-anon/presence/history');

        self::assertResponseStatusCodeSame(401);
    }

    public function testDaysClamped(): void
    {
        $this->seedSamples('hist-clamp');
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request(
            'GET',
            '/api/v1/communities/hist-clamp/presence/history?days=9999',
        );

        self::assertResponseIsSuccessful();
        self::assertCount(3, $response->toArray()['samples']);
    }
}
