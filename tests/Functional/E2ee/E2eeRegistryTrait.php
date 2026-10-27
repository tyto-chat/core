<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\User;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;

trait E2eeRegistryTrait
{
    /** @return array{User, E2eeKeys, Client} */
    private function userWithAccount(): array
    {
        $user = UserFactory::createOne();
        $keys = new E2eeKeys();
        $client = $this->plainJsonClient($user);
        $client->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => $keys->accountPublicKey()]]);
        self::assertResponseStatusCodeSame(201);

        return [$user, $keys, $client];
    }

    /** @return array{body: array{deviceId: string, name: string, publicKey: string, server: string, credential: string}, keyPair: non-empty-string} */
    private function registerDevice(Client $client, E2eeKeys $keys, User $user, string $name = 'Laptop'): array
    {
        $device = $keys->device((int) $user->getId(), name: $name);
        $client->request('POST', '/api/v1/e2ee/devices', ['json' => $device['body']]);
        self::assertResponseStatusCodeSame(201);

        return $device;
    }

    private function sharingPeer(User $user): User
    {
        $peer = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($peer, $community);

        return $peer;
    }
}
