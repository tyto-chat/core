<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\E2ee\HistoryRecordPayload;
use App\Entity\E2eeAccount;
use App\Entity\E2eeDevice;
use App\Entity\User;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BackupTest extends ApiTestCase
{
    use E2eeRegistryTrait;
    use Factories;
    use ResetDatabase;

    /** @var array<string, non-empty-string> */
    private array $deviceKeys = [];

    public function testIdentityBackupRoundtripOpaque(): void
    {
        [, , $client] = $this->userWithAccount();
        $blob = base64_encode(random_bytes(200));

        $client->request('PUT', '/api/v1/e2ee/identity-backup', ['json' => ['blob' => $blob]]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(['blob' => $blob, 'generation' => 1], $client->request('GET', '/api/v1/e2ee/identity-backup')->toArray());
    }

    public function testIdentityBackupWriteOncePerGeneration(): void
    {
        [, , $client] = $this->userWithAccount();
        $client->request('GET', '/api/v1/e2ee/identity-backup');
        self::assertResponseStatusCodeSame(404);
        $client->request('PUT', '/api/v1/e2ee/identity-backup', ['json' => ['blob' => base64_encode('first')]]);
        self::assertResponseStatusCodeSame(204);

        $client->request('PUT', '/api/v1/e2ee/identity-backup', ['json' => ['blob' => base64_encode('second')]]);
        self::assertResponseStatusCodeSame(409);

        self::assertSame(base64_encode('first'), $client->request('GET', '/api/v1/e2ee/identity-backup')->toArray()['blob']);
    }

    public function testHistoryRecordAcceptedOnlyAtHeadPlusOne(): void
    {
        [$client, $device] = $this->ownerWithDevice();

        $skipped = $this->record($client, $device, 2, 'b');
        self::assertSame(409, $skipped->getStatusCode());
        self::assertSame(0, $skipped->toArray(false)['head']);

        self::assertSame(201, $this->record($client, $device, 1, 'a')->getStatusCode());
        self::assertSame(201, $this->record($client, $device, 2, 'b')->getStatusCode());

        $stale = $this->record($client, $device, 2, 'c');
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(2, $stale->toArray(false)['head']);
        self::assertSame(409, $this->record($client, $device, 4, 'd')->getStatusCode());
    }

    public function testHistoryRecordConcurrentWritersFromTwoDevicesBothLand(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $laptop = $this->device($client, $keys, $user);
        $desktop = $this->device($client, $keys, $user, 'Desktop');

        self::assertSame(201, $this->record($client, $laptop, 1, 'laptop-1')->getStatusCode());
        self::assertSame(201, $this->record($client, $desktop, 1, 'desktop-1')->getStatusCode());
        self::assertSame(201, $this->record($client, $laptop, 2, 'laptop-2')->getStatusCode());
        self::assertSame(201, $this->record($client, $desktop, 2, 'desktop-2')->getStatusCode());

        $expected = [$laptop => 2, $desktop => 2];
        ksort($expected);
        self::assertSame($expected, $this->heads($client));
    }

    public function testHistoryRecordResendIdempotentSameBlobConflictDifferentBlob(): void
    {
        [$client, $device] = $this->ownerWithDevice();
        self::assertSame(201, $this->record($client, $device, 1, 'same')->getStatusCode());

        self::assertSame(200, $this->record($client, $device, 1, 'same')->getStatusCode());

        $different = $this->record($client, $device, 1, 'other');
        self::assertSame(409, $different->getStatusCode());
        self::assertSame(1, $different->toArray(false)['head']);
        self::assertCount(1, $this->page($client)['items']);
    }

    public function testHistoryRecordsListedInDeviceCounterOrderWithCursor(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $ids = [
            $this->device($client, $keys, $user),
            $this->device($client, $keys, $user, 'Desktop'),
        ];
        sort($ids);
        [$first, $second] = $ids;
        for ($counter = 1; $counter <= 3; ++$counter) {
            $this->record($client, $second, $counter, "s{$counter}");
            $this->record($client, $first, $counter, "f{$counter}");
        }

        $page = $this->page($client, limit: 4);
        $rest = $this->page($client, (string) $page['next'], limit: 4);

        $seen = array_map(
            static fn (array $item): string => $item['deviceId'].'#'.$item['counter'].':'.base64_decode($item['blob'], true),
            [...$page['items'], ...$rest['items']],
        );
        self::assertSame([
            "{$first}#1:f1", "{$first}#2:f2", "{$first}#3:f3",
            "{$second}#1:s1", "{$second}#2:s2", "{$second}#3:s3",
        ], $seen);
        self::assertNull($rest['next']);
    }

    public function testHistoryHeadsExposed(): void
    {
        [$client, $device] = $this->ownerWithDevice();
        self::assertSame([], $this->heads($client));

        $this->record($client, $device, 1, 'x');

        self::assertSame([$device => 1], $this->heads($client));
    }

    public function testHistoryRecordRequiresTheDeviceSignature(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $device = $this->device($client, $keys, $user);
        $sibling = $this->registerDevice($client, $keys, $user, 'Desktop');

        self::assertSame(422, $this->record($client, $device, 1, 'forged', $sibling['keyPair'])->getStatusCode());
        self::assertSame(422, $client->request('POST', '/api/v1/e2ee/history-backup/'.$device.'/records', ['json' => [
            'counter' => 1,
            'blob' => base64_encode('unsigned'),
        ]])->getStatusCode());
        self::assertSame([], $this->heads($client));
        self::assertSame(201, $this->record($client, $device, 1, 'genuine')->getStatusCode());
    }

    public function testRevokedDeviceCannotWriteHistory(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $kept = $this->registerDevice($client, $keys, $user);
        $lost = $this->registerDevice($client, $keys, $user, 'Phone');
        $this->deviceKeys[$lost['body']['deviceId']] = $lost['keyPair'];
        $this->record($client, $lost['body']['deviceId'], 1, 'before');
        $client->request('DELETE', '/api/v1/e2ee/devices/'.$lost['body']['deviceId'], ['json' => [
            'revocation' => E2eeKeys::revocation($lost['body']['deviceId'], $kept['body']['deviceId'], $kept['keyPair']),
        ]]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame(422, $this->record($client, $lost['body']['deviceId'], 2, 'after')->getStatusCode());
        self::assertCount(1, $this->page($client)['items']);
    }

    public function testResetBumpsGenerationRevokesDevicesReplacesIdentityBackupAndClearsHistory(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $device = $this->device($client, $keys, $user);
        $client->request('PUT', '/api/v1/e2ee/identity-backup', ['json' => ['blob' => base64_encode('old identity')]]);
        $this->record($client, $device, 1, 'old history');
        $newKeys = new E2eeKeys();

        $reset = $client->request('POST', '/api/v1/e2ee/reset', ['json' => [
            'identityBackup' => base64_encode('new identity'),
            'newPublicKey' => $newKeys->accountPublicKey(),
            'generation' => 1,
        ]]);

        self::assertSame(200, $reset->getStatusCode());
        self::assertSame(['generation' => 2], $reset->toArray());
        self::assertSame(['blob' => base64_encode('new identity'), 'generation' => 2], $client->request('GET', '/api/v1/e2ee/identity-backup')->toArray());
        self::assertSame([], $this->page($client)['items']);
        self::assertSame([], $this->heads($client));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $account = $em->getRepository(E2eeAccount::class)->findOneBy(['user' => $user->getId()]);
        self::assertSame($newKeys->accountPublicKey(), $account?->getPublicKey());
        self::assertTrue($em->find(E2eeDevice::class, $device)?->isRevoked());
        self::assertSame(422, $this->record($client, $device, 2, 'stale device')->getStatusCode());
        $client->request('PUT', '/api/v1/e2ee/identity-backup', ['json' => ['blob' => base64_encode('again')]]);
        self::assertResponseStatusCodeSame(409);

        $fresh = $newKeys->device((int) $user->getId(), generation: 2);
        $client->request('POST', '/api/v1/e2ee/devices', ['json' => $fresh['body']]);
        self::assertResponseStatusCodeSame(201);
        $this->deviceKeys[$fresh['body']['deviceId']] = $fresh['keyPair'];
        self::assertSame(201, $this->record($client, $fresh['body']['deviceId'], 1, 'new history')->getStatusCode());
    }

    public function testReplayedResetRefused(): void
    {
        [$user, , $client] = $this->userWithAccount();
        $newKeys = new E2eeKeys();
        $body = ['identityBackup' => base64_encode('id'), 'newPublicKey' => $newKeys->accountPublicKey(), 'generation' => 1];
        $client->request('POST', '/api/v1/e2ee/reset', ['json' => $body]);
        self::assertResponseStatusCodeSame(200);
        $fresh = $newKeys->device((int) $user->getId(), generation: 2);
        $client->request('POST', '/api/v1/e2ee/devices', ['json' => $fresh['body']]);
        self::assertResponseStatusCodeSame(201);

        $replay = $client->request('POST', '/api/v1/e2ee/reset', ['json' => $body]);

        self::assertSame(409, $replay->getStatusCode());
        self::assertSame(2, $replay->toArray(false)['currentGeneration']);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertFalse($em->find(E2eeDevice::class, $fresh['body']['deviceId'])?->isRevoked());
    }

    public function testBackupsOwnerOnly(): void
    {
        [$client, $device] = $this->ownerWithDevice();
        $client->request('PUT', '/api/v1/e2ee/identity-backup', ['json' => ['blob' => base64_encode('mine')]]);
        $this->record($client, $device, 1, 'mine');
        [, , $other] = $this->userWithAccount();

        self::assertSame(404, $this->record($other, $device, 2, 'theirs')->getStatusCode());
        $other->request('GET', '/api/v1/e2ee/identity-backup');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->page($other)['items']);
        self::assertSame([], $this->heads($other));
        $this->plainJsonClient()->request('GET', '/api/v1/e2ee/identity-backup');
        self::assertResponseStatusCodeSame(401);
        $this->plainJsonClient(UserFactory::createOne())->request('GET', '/api/v1/e2ee/history-backup');
        self::assertResponseStatusCodeSame(422);
    }

    public function testOversizedBlobsRejected(): void
    {
        [$client, $device] = $this->ownerWithDevice();

        $client->request('PUT', '/api/v1/e2ee/identity-backup', ['json' => ['blob' => base64_encode(random_bytes(70_000))]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(422, $client->request('POST', '/api/v1/e2ee/history-backup/'.$device.'/records', ['json' => [
            'counter' => 1,
            'blob' => base64_encode(random_bytes(1_100_000)),
        ]])->getStatusCode());
    }

    /** @return array{Client, string} */
    private function ownerWithDevice(): array
    {
        [$user, $keys, $client] = $this->userWithAccount();

        return [$client, $this->device($client, $keys, $user)];
    }

    private function device(Client $client, E2eeKeys $keys, User $user, string $name = 'Laptop'): string
    {
        $device = $this->registerDevice($client, $keys, $user, $name);
        $this->deviceKeys[$device['body']['deviceId']] = $device['keyPair'];

        return $device['body']['deviceId'];
    }

    /** @param non-empty-string|null $signingKeyPair */
    private function record(Client $client, string $deviceId, int $counter, string $content, ?string $signingKeyPair = null, int $generation = 1): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $keyPair = $signingKeyPair ?? $this->deviceKeys[$deviceId] ?? sodium_crypto_sign_keypair();
        $payload = HistoryRecordPayload::encode($deviceId, $this->generationOf($deviceId, $generation), $counter, $content);
        $response = $client->request('POST', '/api/v1/e2ee/history-backup/'.$deviceId.'/records', ['json' => [
            'counter' => $counter,
            'blob' => base64_encode($content),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($keyPair))),
        ]]);
        $response->getStatusCode();

        return $response;
    }

    private function generationOf(string $deviceId, int $fallback): int
    {
        $device = self::getContainer()->get(EntityManagerInterface::class)->find(E2eeDevice::class, $deviceId);

        return $device?->getGeneration() ?? $fallback;
    }

    /** @return array{items: list<array{deviceId: string, counter: int, blob: string}>, next: string|null} */
    private function page(Client $client, ?string $after = null, ?int $limit = null): array
    {
        $query = array_filter(['after' => $after, 'limit' => $limit], static fn (mixed $v): bool => null !== $v);

        /** @var array{items: list<array{deviceId: string, counter: int, blob: string}>, next: string|null} $page */
        $page = $client->request('GET', '/api/v1/e2ee/history-backup'.([] === $query ? '' : '?'.http_build_query($query)))->toArray();

        return $page;
    }

    /** @return array<string, int> */
    private function heads(Client $client): array
    {
        return $client->request('GET', '/api/v1/e2ee/history-backup/heads')->toArray()['heads'];
    }
}
