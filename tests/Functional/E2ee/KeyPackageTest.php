<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\E2ee\KeyPackageUploadPayload;
use App\Entity\User;
use App\Service\E2ee\E2eeServiceInterface;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class KeyPackageTest extends ApiTestCase
{
    use E2eeRegistryTrait;
    use Factories;
    use ResetDatabase;

    /** @var array<string, non-empty-string> */
    private array $deviceKeyPairs = [];

    public function testOneTimePackageConsumedOnce(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $packages = [self::package(), self::package()];
        $lastResort = self::package();
        $this->upload($client, $device, $packages, $lastResort);

        $claimer = $this->claimer($user);
        $first = $this->claim($claimer, $user);
        $second = $this->claim($claimer, $user);

        self::assertSame([['deviceId' => $device, 'keyPackage' => $packages[0]['data']]], $first);
        self::assertSame([['deviceId' => $device, 'keyPackage' => $packages[1]['data']]], $second);
        self::assertSame($lastResort['data'], $this->claim($claimer, $user)[0]['keyPackage']);
    }

    public function testLastResortUsedWhenPoolEmpty(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $one = self::package();
        $lastResort = self::package();
        $this->upload($client, $device, [$one], $lastResort);

        $claimer = $this->claimer($user);

        self::assertSame($one['data'], $this->claim($claimer, $user)[0]['keyPackage']);
        self::assertSame($lastResort['data'], $this->claim($claimer, $user)[0]['keyPackage']);
        self::assertSame($lastResort['data'], $this->claim($claimer, $user)[0]['keyPackage']);
    }

    public function testExpiredOneTimePackagesSkipped(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $expired = self::package();
        $fresh = self::package();
        $this->upload($client, $device, [$expired, $fresh], self::package());
        $this->expire($expired['data']);

        self::assertSame($fresh['data'], $this->claim($this->claimer($user), $user)[0]['keyPackage']);
    }

    public function testExpiredLastResortMakesDeviceUnreachable(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $lastResort = self::package();
        $this->upload($client, $device, [], $lastResort);
        self::assertTrue($this->service()->isReachable($user));

        $this->expire($lastResort['data']);

        self::assertFalse($this->service()->isReachable($user));
        self::assertSame(409, $this->sendClaim($this->claimer($user), $user)->getStatusCode());
    }

    public function testOneTimePackagesStillServedAfterTheLastResortExpires(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $oneTime = self::package();
        $lastResort = self::package();
        $this->upload($client, $device, [$oneTime], $lastResort);
        $this->expire($lastResort['data']);

        self::assertTrue($this->service()->isReachable($user));
        $claimer = $this->claimer($user);
        self::assertSame($oneTime['data'], $this->claim($claimer, $user)[0]['keyPackage']);
        self::assertFalse($this->service()->isReachable($user));
        self::assertSame(409, $this->sendClaim($claimer, $user)->getStatusCode());
    }

    public function testEmptyPoolWithoutLastResortMakesDeviceUnreachable(): void
    {
        [$user] = $this->userWithDevice();

        self::assertFalse($this->service()->isReachable($user));
        $response = $this->sendClaim($this->claimer($user), $user);
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString((string) $user->getProfile()?->getName(), $response->getContent(false));
    }

    public function testUploadRefusedWhileTheDeviceHasNoLastResort(): void
    {
        [, $device, $client] = $this->userWithDevice();

        $response = $this->sendUpload($client, $device, [self::package()], null);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('This device must publish a fallback key package first.', $response->toArray(false)['error']);
        self::assertSame(0, $this->oneTimeCount($device));

        $this->upload($client, $device, [], self::package());
        $this->upload($client, $device, [self::package()]);
        self::assertSame(1, $this->oneTimeCount($device));
    }

    public function testFallbackRepeatingAOneTimePackageOfTheSameUploadIsRefused(): void
    {
        [, $device, $client] = $this->userWithDevice();
        $package = self::package();

        $response = $this->sendUpload($client, $device, [$package], $package);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(0, $this->oneTimeCount($device));
    }

    public function testUploadMustBeSignedByTheTargetDevice(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $target = $this->device($client, $keys, $user);
        $sibling = $this->device($client, $keys, $user);
        $packages = [self::package()];
        $lastResort = self::package();

        $response = $this->sendUpload($client, $target, $packages, $lastResort, signer: $this->deviceKeyPairs[$sibling]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The key package upload is not signed by this device.', $response->toArray(false)['error']);

        $response = $client->request('POST', '/api/v1/e2ee/devices/'.$target.'/key-packages', ['json' => [
            'packages' => [['data' => $packages[0]['data'], 'expiresAt' => $packages[0]['expiresAt'] + 1]],
            'lastResort' => $lastResort,
            'signature' => $this->signature($target, $packages, $lastResort),
        ]]);
        self::assertSame(422, $response->getStatusCode(), 'a signature covers the expiry');

        $response = $this->sendUpload($client, $sibling, $packages, $lastResort, signer: $this->deviceKeyPairs[$target]);
        self::assertSame(422, $response->getStatusCode(), 'a signature by one device does not authorise an upload to another');

        self::assertSame(0, $this->oneTimeCount($target) + $this->oneTimeCount($sibling));
        self::assertFalse($this->service()->isReachable($user));
    }

    public function testUploadRequiresASignature(): void
    {
        [, $device, $client] = $this->userWithDevice();

        $response = $client->request('POST', '/api/v1/e2ee/devices/'.$device.'/key-packages', ['json' => ['packages' => [], 'lastResort' => self::package()]]);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testRevokedDeviceYieldsNoPackage(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $kept = $this->device($client, $keys, $user);
        $revoked = $this->device($client, $keys, $user);
        $this->upload($client, $kept, [self::package()], self::package());
        $this->upload($client, $revoked, [self::package()], self::package());

        $response = $client->request('DELETE', '/api/v1/e2ee/devices/'.$revoked, ['json' => [
            'revocation' => E2eeKeys::revocation($revoked, $kept, $this->deviceKeyPairs[$kept]),
        ]]);
        self::assertSame(204, $response->getStatusCode());

        self::assertSame(422, $this->sendUpload($client, $revoked, [self::package()], self::package())->getStatusCode());

        $claimed = $this->claim($this->claimer($user), $user);

        self::assertSame([$kept], array_column($claimed, 'deviceId'));
        self::assertSame(0, $this->oneTimeCount($revoked));
    }

    public function testClaimReturnsOnePackagePerReachableDevice(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $a = $this->device($client, $keys, $user);
        $b = $this->device($client, $keys, $user);
        $empty = $this->device($client, $keys, $user);
        $this->upload($client, $a, [self::package()], self::package());
        $this->upload($client, $b, [], self::package());

        $claimed = $this->claim($this->claimer($user), $user);

        $ids = array_column($claimed, 'deviceId');
        sort($ids);
        $expected = [$a, $b];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertNotContains($empty, $ids);
    }

    public function testClaimRequiresAnAccountOfTheCaller(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $this->upload($client, $device, [self::package()], self::package());

        $response = $this->sendClaim($this->plainJsonClient($this->sharingPeer($user)), $user);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $this->oneTimeCount($device));
    }

    public function testConcurrentClaimsNeverShareAPackage(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $packages = array_map(static fn (): array => self::package(), range(1, 5));
        $this->upload($client, $device, $packages, self::package());

        $claimer = $this->claimer($user);
        $claimed = [];
        foreach (range(1, 5) as $ignored) {
            $claimed[] = $this->claim($claimer, $user)[0]['keyPackage'];
        }

        self::assertSame(array_column($packages, 'data'), $claimed);
        self::assertSame(0, $this->oneTimeCount($device));
    }

    public function testRetriedUploadNeverStoresAPackageTwice(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $package = self::package();
        $lastResort = self::package();
        $this->upload($client, $device, [$package, $package], $lastResort);
        $this->upload($client, $device, [$package]);

        self::assertSame(1, $this->oneTimeCount($device));

        $claimer = $this->claimer($user);
        self::assertSame($package['data'], $this->claim($claimer, $user)[0]['keyPackage']);

        $this->upload($client, $device, [$package]);
        self::assertSame($lastResort['data'], $this->claim($claimer, $user)[0]['keyPackage'], 'a consumed package is not resurrected by a late retry');
    }

    public function testNonOwnerCannotUpload(): void
    {
        [$user, $device] = $this->userWithDevice();

        $response = $this->sendUpload($this->plainJsonClient($this->sharingPeer($user)), $device, [self::package()], self::package());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->oneTimeCount($device));
    }

    public function testUploadValidatesPackages(): void
    {
        [, $device, $client] = $this->userWithDevice();

        foreach ([
            'expired' => ['data' => base64_encode(random_bytes(64)), 'expiresAt' => time() - 1],
            'too far ahead' => ['data' => base64_encode(random_bytes(64)), 'expiresAt' => time() + 400 * 86400],
            'not base64' => ['data' => '!!!', 'expiresAt' => time() + 3600],
            'too large' => ['data' => base64_encode(random_bytes(4097)), 'expiresAt' => time() + 3600],
        ] as $case => $package) {
            self::assertSame(422, $this->sendUpload($client, $device, [$package], self::package())->getStatusCode(), $case);
        }
    }

    public function testPoolIsCappedAtOneHundred(): void
    {
        [, $device, $client] = $this->userWithDevice();
        $this->upload($client, $device, array_map(static fn (): array => self::package(), range(1, 100)), self::package());

        self::assertSame(422, $this->sendUpload($client, $device, [self::package()], null)->getStatusCode());
        self::assertSame(100, $this->oneTimeCount($device));
    }

    public function testConsumedPackageStaysConsumedAfterItsRowExpires(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $package = self::package();
        $lastResort = self::package();
        $this->upload($client, $device, [$package], $lastResort);
        $claimer = $this->claimer($user);
        $this->claim($claimer, $user);
        $this->connection()->executeStatement('UPDATE e2ee_key_package SET expires_at = :past', ['past' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);

        $freshLastResort = self::package();
        $this->upload($client, $device, [['data' => $package['data'], 'expiresAt' => time() + 86400]], $freshLastResort, replaces: $lastResort);

        self::assertSame($freshLastResort['data'], $this->claim($claimer, $user)[0]['keyPackage']);
        self::assertSame(1, (int) $this->connection()->fetchOne("SELECT COUNT(*) FROM e2ee_key_package WHERE data <> ''"));
    }

    public function testDelayedRetryCannotRestoreASupersededLastResort(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $old = self::package();
        $current = self::package();
        $this->upload($client, $device, [], $old);
        $this->upload($client, $device, [], $current, replaces: $old);

        $this->upload($client, $device, [], $old);

        self::assertSame($current['data'], $this->claim($this->claimer($user), $user)[0]['keyPackage']);
    }

    public function testDelayedFallbackRotationIsRefusedNotApplied(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $first = self::package();
        $second = self::package();
        $third = self::package();
        $this->upload($client, $device, [], $first);

        $response = $this->sendUpload($client, $device, [self::package()], $third, replaces: $second);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('The fallback key package changed in the meantime; refresh and try again.', $response->toArray(false)['error']);
        self::assertSame(0, $this->oneTimeCount($device));

        $this->upload($client, $device, [], $second, replaces: $first);
        $this->upload($client, $device, [], $third, replaces: $second);
        $this->upload($client, $device, [], $third, replaces: $second);

        self::assertSame(409, $this->sendUpload($client, $device, [], self::package(), replaces: $first)->getStatusCode());
        self::assertSame($third['data'], $this->claim($this->claimer($user), $user)[0]['keyPackage']);
    }

    public function testExpiredFallbackStillOrdersItsReplacement(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $first = self::package();
        $second = self::package();
        $this->upload($client, $device, [], $first);
        $this->upload($client, $device, [], $second, replaces: $first);
        $this->expire($second['data']);

        self::assertSame(409, $this->sendUpload($client, $device, [], self::package(), replaces: $first)->getStatusCode());
        self::assertSame(409, $this->sendUpload($client, $device, [], self::package())->getStatusCode());

        $third = self::package();
        $this->upload($client, $device, [], $third, replaces: $second);
        self::assertSame($third['data'], $this->claim($this->claimer($user), $user)[0]['keyPackage']);
    }

    public function testOwnDeviceListTellsADeviceWhichFallbackToReplace(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $devices = $client->request('GET', '/api/v1/e2ee/devices')->toArray()['items'];
        self::assertNull($devices[0]['lastResortFingerprint'] ?? null);

        $lost = self::package();
        $this->upload($client, $device, [], $lost);
        $devices = $client->request('GET', '/api/v1/e2ee/devices')->toArray()['items'];
        self::assertSame(base64_encode(self::fingerprint($lost)), $devices[0]['lastResortFingerprint']);

        $next = self::package();
        $response = $client->request('POST', '/api/v1/e2ee/devices/'.$device.'/key-packages', ['json' => [
            'packages' => [],
            'lastResort' => $next,
            'lastResortReplaces' => $devices[0]['lastResortFingerprint'],
            'signature' => base64_encode(sodium_crypto_sign_detached(
                KeyPackageUploadPayload::encode($device, 1, [], $next, (string) base64_decode($devices[0]['lastResortFingerprint'], true)),
                sodium_crypto_sign_secretkey($this->deviceKeyPairs[$device]),
            )),
        ]]);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame($next['data'], $this->claim($this->claimer($user), $user)[0]['keyPackage']);
    }

    public function testReplacementMayExpireSoonerThanTheFallbackItReplaces(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $current = self::package(60);
        $this->upload($client, $device, [], $current);
        $sameExpiry = ['data' => base64_encode(random_bytes(283)), 'expiresAt' => $current['expiresAt']];
        $shorter = self::package(10);

        $this->upload($client, $device, [], $sameExpiry, replaces: $current);
        $this->upload($client, $device, [], $shorter, replaces: $sameExpiry);

        self::assertSame($shorter['data'], $this->claim($this->claimer($user), $user)[0]['keyPackage']);
    }

    public function testRetryingAFullPoolUploadSucceeds(): void
    {
        [, $device, $client] = $this->userWithDevice();
        $packages = array_map(static fn (): array => self::package(), range(1, 100));
        $lastResort = self::package();
        $this->upload($client, $device, $packages, $lastResort);

        $this->upload($client, $device, $packages, $lastResort);

        self::assertSame(100, $this->oneTimeCount($device));
    }

    public function testNewLastResortReplacesTheOldOne(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $original = self::package();
        $this->upload($client, $device, [], $original);
        $replacement = self::package();
        $this->upload($client, $device, [], $replacement, replaces: $original);

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM e2ee_key_package WHERE device_id = :id AND claimed_at IS NULL AND last_resort = 1', ['id' => $device]));
        self::assertSame($replacement['data'], $this->claim($this->claimer($user), $user)[0]['keyPackage']);
    }

    public function testEncryptedConversationPeerWithoutSharedCommunityCanClaim(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $this->upload($client, $device, [self::package()], self::package());
        $peer = UserFactory::createOne();
        $this->sharedConversation([$user, $peer], encrypted: true);
        $peerClient = $this->plainJsonClient($peer);
        $peerClient->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => (new E2eeKeys())->accountPublicKey()]]);

        self::assertSame(200, $this->sendClaim($peerClient, $user)->getStatusCode());
    }

    public function testStrangerCannotClaim(): void
    {
        [$user, $device, $client] = $this->userWithDevice();
        $this->upload($client, $device, [self::package()], self::package());
        $stranger = $this->plainJsonClient(UserFactory::createOne());
        $stranger->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => (new E2eeKeys())->accountPublicKey()]]);

        self::assertSame(404, $this->sendClaim($stranger, $user)->getStatusCode());
        self::assertSame(1, $this->oneTimeCount($device));
    }

    public function testPatUsersGet403(): void
    {
        [$user, $device] = $this->userWithDevice();
        $pat = $this->apiKeyClient($user);

        $pat->request('POST', '/api/v1/e2ee/devices/'.$device.'/key-packages', ['json' => ['packages' => [self::package()]]]);
        self::assertResponseStatusCodeSame(403);
        $pat->request('POST', '/api/v1/e2ee/users/'.$user->getId().'/key-package', ['json' => []]);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array{User, string, Client} */
    private function userWithDevice(): array
    {
        [$user, $keys, $client] = $this->userWithAccount();

        return [$user, $this->device($client, $keys, $user), $client];
    }

    private function device(Client $client, E2eeKeys $keys, User $user): string
    {
        $device = $this->registerDevice($client, $keys, $user);
        $this->deviceKeyPairs[$device['body']['deviceId']] = $device['keyPair'];

        return $device['body']['deviceId'];
    }

    private function claimer(User $user): Client
    {
        $client = $this->plainJsonClient($this->sharingPeer($user));
        $response = $client->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => (new E2eeKeys())->accountPublicKey()]]);
        self::assertSame(201, $response->getStatusCode());

        return $client;
    }

    /** @return array{data: string, expiresAt: int} */
    private static function package(int $days = 30): array
    {
        return ['data' => base64_encode(random_bytes(283)), 'expiresAt' => time() + $days * 86400];
    }

    /**
     * @param list<array{data: string, expiresAt: int}> $packages
     * @param array{data: string, expiresAt: int}|null  $lastResort
     * @param array{data: string, expiresAt: int}|null  $replaces
     * @param non-empty-string|null                     $signer
     */
    private function signature(string $deviceId, array $packages, ?array $lastResort, ?array $replaces = null, ?string $signer = null): string
    {
        return base64_encode(sodium_crypto_sign_detached(
            KeyPackageUploadPayload::encode($deviceId, 1, $packages, $lastResort, null === $replaces ? null : self::fingerprint($replaces)),
            sodium_crypto_sign_secretkey($signer ?? $this->deviceKeyPairs[$deviceId]),
        ));
    }

    /**
     * @param list<array{data: string, expiresAt: int}> $packages
     * @param array{data: string, expiresAt: int}|null  $lastResort
     * @param array{data: string, expiresAt: int}|null  $replaces
     * @param non-empty-string|null                     $signer
     */
    private function sendUpload(Client $client, string $deviceId, array $packages, ?array $lastResort, ?array $replaces = null, ?string $signer = null): ResponseInterface
    {
        $body = ['packages' => $packages, 'signature' => $this->signature($deviceId, $packages, $lastResort, $replaces, $signer)];
        if (null !== $lastResort) {
            $body['lastResort'] = $lastResort;
        }
        if (null !== $replaces) {
            $body['lastResortReplaces'] = base64_encode(self::fingerprint($replaces));
        }

        return $client->request('POST', '/api/v1/e2ee/devices/'.$deviceId.'/key-packages', ['json' => $body]);
    }

    /**
     * @param list<array{data: string, expiresAt: int}> $packages
     * @param array{data: string, expiresAt: int}|null  $lastResort
     * @param array{data: string, expiresAt: int}|null  $replaces
     */
    private function upload(Client $client, string $deviceId, array $packages, ?array $lastResort = null, ?array $replaces = null): void
    {
        $response = $this->sendUpload($client, $deviceId, $packages, $lastResort, $replaces);
        self::assertSame(204, $response->getStatusCode(), $response->getContent(false));
    }

    /** @param array{data: string, expiresAt: int} $package */
    private static function fingerprint(array $package): string
    {
        return hash('sha256', (string) base64_decode($package['data'], true), true);
    }

    private function sendClaim(Client $client, User $user): ResponseInterface
    {
        return $client->request('POST', '/api/v1/e2ee/users/'.$user->getId().'/key-package', ['json' => []]);
    }

    /** @return list<array{deviceId: string, keyPackage: string}> */
    private function claim(Client $client, User $user): array
    {
        $response = $this->sendClaim($client, $user);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        /** @var list<array{deviceId: string, keyPackage: string}> $items */
        $items = $response->toArray()['items'];

        return $items;
    }

    private function expire(string $data): void
    {
        $this->connection()->executeStatement('UPDATE e2ee_key_package SET expires_at = :past WHERE data = :data', [
            'past' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'),
            'data' => $data,
        ]);
    }

    private function oneTimeCount(string $deviceId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM e2ee_key_package WHERE device_id = :id AND claimed_at IS NULL AND last_resort = 0', ['id' => $deviceId]);
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get(Connection::class);
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function service(): E2eeServiceInterface
    {
        $service = static::getContainer()->get(E2eeServiceInterface::class);
        \assert($service instanceof E2eeServiceInterface);

        return $service;
    }

    /** @param list<User> $users */
    private function sharedConversation(array $users, bool $encrypted): void
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $users);
        $conversation = \App\Tests\Factory\ConversationFactory::new(['encrypted' => $encrypted])
            ->with(['participantsHash' => \App\Entity\Conversation::hashParticipants($ids, $encrypted)])
            ->create();
        foreach ($users as $user) {
            \App\Tests\Factory\ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        }
    }
}
