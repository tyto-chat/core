<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Repository\CommunityRepository;
use App\Service\Presence\GuestPresenceServiceInterface;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemoryGuestPresenceService;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class GuestPresenceTrackingTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function guestStub(): InMemoryGuestPresenceService
    {
        $svc = static::getContainer()->get(GuestPresenceServiceInterface::class);
        \assert($svc instanceof InMemoryGuestPresenceService);

        return $svc;
    }

    private function communityEntity(string $identifier): \App\Entity\Community
    {
        $community = static::getContainer()->get(CommunityRepository::class)
            ->findOneBy(['identifier' => $identifier]);
        self::assertNotNull($community);

        return $community;
    }

    public function testAnonymousRequestOnPublicCommunityRegistersGuest(): void
    {
        CommunityFactory::new()->withIdentifier('guest-pub')->create();

        $this->plainJsonClient()->request('GET', '/api/v1/communities/guest-pub/presence/summary');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->guestStub()->getGuestCount($this->communityEntity('guest-pub')));
    }

    public function testAnonymousRequestOnPrivateCommunityDoesNotRegister(): void
    {
        CommunityFactory::new()->withIdentifier('guest-priv')->private()->create();

        $this->plainJsonClient()->request('GET', '/api/v1/communities/guest-priv/presence/summary');

        self::assertSame(0, $this->guestStub()->getGuestCount($this->communityEntity('guest-priv')));
    }

    public function testAuthenticatedRequestDoesNotRegisterGuest(): void
    {
        $user = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('guest-auth')->create();

        $this->plainJsonClient($user)->request('GET', '/api/v1/communities/guest-auth/presence/summary');

        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->guestStub()->getGuestCount($this->communityEntity('guest-auth')));
    }
}
