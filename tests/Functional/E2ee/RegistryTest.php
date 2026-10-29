<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use App\E2ee\ByteString;
use App\E2ee\CanonicalCbor;
use App\Exception\E2ee\InvalidE2eeRevocationException;
use App\Service\E2ee\E2eeServiceInterface;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class RegistryTest extends ApiTestCase
{
    use E2eeRegistryTrait;
    use Factories;
    use ResetDatabase;

    public function testAccountRegisteredOnce(): void
    {
        $user = UserFactory::createOne();
        $keys = new E2eeKeys();
        $client = $this->plainJsonClient($user);

        $account = $client->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => $keys->accountPublicKey()]])->toArray();
        self::assertResponseStatusCodeSame(201);
        self::assertSame($keys->accountPublicKey(), $account['publicKey']);
        self::assertSame(1, $account['generation']);

        $client->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => (new E2eeKeys())->accountPublicKey()]]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testAccountPublicKeyMustBeAnEd25519Key(): void
    {
        $client = $this->plainJsonClient(UserFactory::createOne());

        $client->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => base64_encode('short')]]);
        self::assertResponseStatusCodeSame(422);

        $client->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => 'not base64 !!']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testActiveDevicesAreCappedPerAccount(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $devices = [];
        foreach (range(1, E2eeServiceInterface::MAX_ACTIVE_DEVICES) as $ignored) {
            $devices[] = $this->registerDevice($client, $keys, $user);
        }

        $response = $client->request('POST', '/api/v1/e2ee/devices', ['json' => $keys->device((int) $user->getId())['body']]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('This account already has the maximum number of devices. Remove one first.', $response->toArray(false)['error']);

        $client->request('DELETE', '/api/v1/e2ee/devices/'.$devices[1]['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($devices[1]['body']['deviceId'], $devices[0]['body']['deviceId'], $devices[0]['keyPair']),
        ]]);
        self::assertResponseStatusCodeSame(204);
        $this->registerDevice($client, $keys, $user);
    }

    public function testDeviceRequiresAccount(): void
    {
        $user = UserFactory::createOne();
        $client = $this->plainJsonClient($user);

        $client->request('POST', '/api/v1/e2ee/devices', ['json' => (new E2eeKeys())->device((int) $user->getId())['body']]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeviceRegisteredWithValidCredential(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $device = $keys->device((int) $user->getId());

        $created = $client->request('POST', '/api/v1/e2ee/devices', ['json' => $device['body']])->toArray();

        self::assertResponseStatusCodeSame(201);
        self::assertSame($device['body']['deviceId'], $created['id']);
        self::assertSame('Laptop', $created['name']);
        self::assertSame($device['body']['publicKey'], $created['publicKey']);
        self::assertSame($device['body']['credential'], $created['credential']);
        self::assertSame(1, $created['generation']);
        self::assertSame(E2eeKeys::SERVER, $created['server']);
    }

    public function testDeviceRequiresValidCredentialSignature(): void
    {
        [$user, , $client] = $this->userWithAccount();
        $signedByAnotherKey = (new E2eeKeys())->device((int) $user->getId());

        $client->request('POST', '/api/v1/e2ee/devices', ['json' => $signedByAnotherKey['body']]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testDeviceCredentialBoundToServerAccountAndGeneration(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $userId = (int) $user->getId();

        foreach ([
            'other server' => $keys->device($userId, server: 'https://elsewhere.example'),
            'other account' => $keys->device($userId + 1000),
            'other generation' => $keys->device($userId, generation: 2),
        ] as $case => $device) {
            $client->request('POST', '/api/v1/e2ee/devices', ['json' => $device['body']]);
            self::assertResponseStatusCodeSame(422, $case);
        }
    }

    public function testDeviceServerMustBeTheCanonicalOrigin(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $userId = (int) $user->getId();

        foreach (['http://LOCALHOST', 'http://localhost/', 'http://localhost:80', 'HTTP://localhost'] as $server) {
            $client->request('POST', '/api/v1/e2ee/devices', ['json' => $keys->device($userId, server: $server)['body']]);
            self::assertResponseStatusCodeSame(422, $server);
        }

        $client->request('POST', 'https://localhost/api/v1/e2ee/devices', ['json' => $keys->device($userId, server: 'http://localhost')['body']]);
        self::assertResponseStatusCodeSame(422, 'plain http signed on an https request');

        $client->request('POST', 'https://localhost/api/v1/e2ee/devices', ['json' => $keys->device($userId, server: 'https://localhost')['body']]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testDeviceIdCannotBeRegisteredTwice(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $device = $keys->device((int) $user->getId());
        $client->request('POST', '/api/v1/e2ee/devices', ['json' => $device['body']]);

        $client->request('POST', '/api/v1/e2ee/devices', ['json' => $keys->device((int) $user->getId(), deviceId: $device['body']['deviceId'])['body']]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testDeviceListExcludesRevoked(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $first = $this->registerDevice($client, $keys, $user);
        $second = $this->registerDevice($client, $keys, $user, 'Desktop');

        $client->request('DELETE', '/api/v1/e2ee/devices/'.$second['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($second['body']['deviceId'], $first['body']['deviceId'], $first['keyPair']),
        ]]);
        self::assertResponseStatusCodeSame(204);

        $own = $client->request('GET', '/api/v1/e2ee/devices')->toArray();
        self::assertSame([$first['body']['deviceId']], array_column($own['items'], 'id'));
        self::assertSame('Laptop', $own['items'][0]['name']);

        $peer = $this->sharingPeer($user);
        $listed = $this->plainJsonClient($peer)->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/devices')->toArray();
        self::assertSame($keys->accountPublicKey(), $listed['accountPublicKey']);
        self::assertSame(1, $listed['generation']);
        self::assertSame([$first['body']['deviceId']], array_column($listed['devices'], 'id'));
        self::assertArrayNotHasKey('name', $listed['devices'][0]);
        self::assertSame(E2eeKeys::SERVER, $listed['devices'][0]['server']);
    }

    public function testUserWithoutAccountListsNoDevices(): void
    {
        $user = UserFactory::createOne();

        $listed = $this->plainJsonClient($this->sharingPeer($user))->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/devices')->toArray();

        self::assertNull($listed['accountPublicKey']);
        self::assertSame([], $listed['devices']);
    }

    public function testStrangerCannotListDevices(): void
    {
        [$user] = $this->userWithAccount();

        $this->plainJsonClient(UserFactory::createOne())->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/devices');
        self::assertResponseStatusCodeSame(404);

        $this->plainJsonClient(UserFactory::createOne())->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/revocations');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRevocationRequiresSiblingDeviceSignatureOrOwnLeaveRequest(): void
    {
        [$other, $otherKeys, $otherClient] = $this->userWithAccount();
        $foreign = $this->registerDevice($otherClient, $otherKeys, $other);

        [$user, $keys, $client] = $this->userWithAccount();
        $a = $this->registerDevice($client, $keys, $user);
        $b = $this->registerDevice($client, $keys, $user);
        $c = $this->registerDevice($client, $keys, $user);

        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($b['body']['deviceId'], $foreign['body']['deviceId'], $foreign['keyPair']),
        ]]);
        self::assertResponseStatusCodeSame(422, 'foreign device as signer');

        $forged = E2eeKeys::revocation($b['body']['deviceId'], $a['body']['deviceId'], $c['keyPair']);
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => ['revocation' => $forged]]);
        self::assertResponseStatusCodeSame(422, 'signature does not match the named signer');

        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($b['body']['deviceId'], $a['body']['deviceId'], $a['keyPair']),
        ]]);
        self::assertResponseStatusCodeSame(204, 'sibling');

        $client->request('DELETE', '/api/v1/e2ee/devices/'.$c['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($c['body']['deviceId'], $b['body']['deviceId'], $b['keyPair']),
        ]]);
        self::assertResponseStatusCodeSame(422, 'revoked device as signer');

        $client->request('DELETE', '/api/v1/e2ee/devices/'.$c['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($c['body']['deviceId'], $c['body']['deviceId'], $c['keyPair']),
        ]]);
        self::assertResponseStatusCodeSame(204, 'own leave request');
    }

    public function testRevocationRereadsSignerStateCommittedByAnotherRequest(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $a = $this->registerDevice($client, $keys, $user);
        $c = $this->registerDevice($client, $keys, $user);

        $container = static::getContainer();
        $tokenStorage = $container->get(TokenStorageInterface::class);
        \assert($tokenStorage instanceof TokenStorageInterface);
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
        $service = $container->get(E2eeServiceInterface::class);
        \assert($service instanceof E2eeServiceInterface);
        self::assertCount(2, $service->listOwnDevices());

        $container->get(Connection::class)->executeStatement(
            'UPDATE e2ee_device SET revoked_at = NOW(), revocation = :revocation WHERE id = :id',
            ['revocation' => json_encode(E2eeKeys::revocation($a['body']['deviceId'], $a['body']['deviceId'], $a['keyPair']), \JSON_THROW_ON_ERROR), 'id' => $a['body']['deviceId']],
        );

        $revocation = E2eeKeys::revocation($c['body']['deviceId'], $a['body']['deviceId'], $a['keyPair']);
        $this->expectException(InvalidE2eeRevocationException::class);
        $service->revokeDevice($c['body']['deviceId'], $revocation['signerDeviceId'], $revocation['revokedAt'], $revocation['signature']);
    }

    public function testDeviceIdsMustBeLowercase(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $client->request('POST', '/api/v1/e2ee/devices', ['json' => $keys->device((int) $user->getId(), deviceId: strtoupper(Uuid::v4()->toRfc4122()))['body']]);
        self::assertResponseStatusCodeSame(422, 'uppercase device id');

        $a = $this->registerDevice($client, $keys, $user);
        $b = $this->registerDevice($client, $keys, $user);
        $revocation = E2eeKeys::revocation($b['body']['deviceId'], strtoupper($a['body']['deviceId']), $a['keyPair']);
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => ['revocation' => $revocation]]);
        self::assertResponseStatusCodeSame(422, 'uppercase signer id');

        self::assertCount(2, $client->request('GET', '/api/v1/e2ee/devices')->toArray()['items']);
    }

    public function testRevocationIsIdempotent(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $a = $this->registerDevice($client, $keys, $user);
        $b = $this->registerDevice($client, $keys, $user);
        $revocation = E2eeKeys::revocation($b['body']['deviceId'], $a['body']['deviceId'], $a['keyPair']);

        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => ['revocation' => $revocation]]);
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => ['revocation' => $revocation]]);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $client->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/revocations')->toArray()['items']);
    }

    public function testOtherUserCannotDeleteDevice(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $device = $this->registerDevice($client, $keys, $user);

        $this->plainJsonClient($this->sharingPeer($user))->request('DELETE', '/api/v1/e2ee/devices/'.$device['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($device['body']['deviceId'], $device['body']['deviceId'], $device['keyPair']),
        ]]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $client->request('GET', '/api/v1/e2ee/devices')->toArray()['items']);
    }

    public function testRevocationsListableByPeers(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $a = $this->registerDevice($client, $keys, $user);
        $b = $this->registerDevice($client, $keys, $user);
        $revocation = E2eeKeys::revocation($b['body']['deviceId'], $a['body']['deviceId'], $a['keyPair']);
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => ['revocation' => $revocation]]);

        $listed = $this->plainJsonClient($this->sharingPeer($user))->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/revocations')->toArray();

        self::assertSame([[
            'deviceId' => $b['body']['deviceId'],
            'generation' => 1,
            'revokedAt' => $revocation['revokedAt'],
            'signerDeviceId' => $a['body']['deviceId'],
            'signature' => $revocation['signature'],
            'signerPublicKey' => $a['body']['publicKey'],
            'signerCredential' => $a['body']['credential'],
            'signerServer' => E2eeKeys::SERVER,
            'leaveRequestId' => null,
        ]], $listed['items']);
    }

    public function testRevocationsStayVerifiableAfterTheSignerIsRevoked(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $a = $this->registerDevice($client, $keys, $user);
        $b = $this->registerDevice($client, $keys, $user);
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$b['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($b['body']['deviceId'], $a['body']['deviceId'], $a['keyPair']),
        ]]);
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$a['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($a['body']['deviceId'], $a['body']['deviceId'], $a['keyPair']),
        ]]);

        $peerClient = $this->plainJsonClient($this->sharingPeer($user));
        $devices = $peerClient->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/devices')->toArray();
        $items = $peerClient->request('GET', '/api/v1/e2ee/users/'.$user->getId().'/revocations')->toArray()['items'];

        self::assertSame([], $devices['devices']);
        self::assertCount(2, $items);
        $accountKey = $this->decode($devices['accountPublicKey']);
        foreach ($items as $item) {
            $signerKey = $this->decode($item['signerPublicKey']);
            $credentialPayload = CanonicalCbor::encodeMap([
                'server' => $item['signerServer'],
                'accountId' => (int) $user->getId(),
                'deviceId' => $item['signerDeviceId'],
                'generation' => $item['generation'],
                'devicePublicKey' => new ByteString($signerKey),
            ]);
            self::assertTrue(sodium_crypto_sign_verify_detached($this->decode($item['signerCredential']), $credentialPayload, $accountKey));

            $revocationPayload = CanonicalCbor::encodeMap([
                'deviceId' => $item['deviceId'],
                'generation' => $item['generation'],
                'revokedAt' => $item['revokedAt'],
                'signerDeviceId' => $item['signerDeviceId'],
            ]);
            self::assertTrue(sodium_crypto_sign_verify_detached($this->decode($item['signature']), $revocationPayload, $signerKey));
        }
    }

    /** @return non-empty-string */
    private function decode(mixed $base64): string
    {
        $bytes = \is_string($base64) ? base64_decode($base64, true) : false;
        self::assertIsString($bytes);
        self::assertNotSame('', $bytes);

        return $bytes;
    }

    public function testBotsAndPatUsersGet403(): void
    {
        $bot = UserFactory::new()->bot()->create();
        $this->apiKeyClient($bot)->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => (new E2eeKeys())->accountPublicKey()]]);
        self::assertResponseStatusCodeSame(403);

        $user = UserFactory::createOne();
        $pat = $this->apiKeyClient($user);
        foreach ([
            ['POST', '/api/v1/e2ee/account'],
            ['POST', '/api/v1/e2ee/devices'],
            ['GET', '/api/v1/e2ee/devices'],
            ['GET', '/api/v1/e2ee/users/'.$user->getId().'/devices'],
            ['GET', '/api/v1/e2ee/users/'.$user->getId().'/revocations'],
        ] as [$method, $url]) {
            $pat->request($method, $url, ['json' => []]);
            self::assertResponseStatusCodeSame(403, $method.' '.$url);
        }
    }

    public function testIsReachableFalseWithoutDevice(): void
    {
        [$user] = $this->userWithAccount();

        self::assertFalse(static::getContainer()->get(E2eeServiceInterface::class)->isReachable($user));
        self::assertFalse(static::getContainer()->get(E2eeServiceInterface::class)->isReachable(UserFactory::createOne()));
    }

    public function testIsReachableFalseWithOnlyRevokedDevices(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $device = $this->registerDevice($client, $keys, $user);
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$device['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($device['body']['deviceId'], $device['body']['deviceId'], $device['keyPair']),
        ]]);

        self::assertFalse(static::getContainer()->get(E2eeServiceInterface::class)->isReachable($user));
    }
}
