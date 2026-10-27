<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use App\Entity\Setting;
use App\Settings\Settings;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class OperatorModeTest extends ApiTestCase
{
    use E2eeRegistryTrait;
    use Factories;
    use ResetDatabase;

    public function testDefaultModeIsEnabled(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $config = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config')->toArray();
        self::assertSame('enabled', $config['encryptedDmsMode']);

        $user = UserFactory::createOne();
        $this->plainJsonClient($user)->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => (new E2eeKeys())->accountPublicKey()]]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testNewAccountSetupBlockedWhenDisabled(): void
    {
        $this->patchMode('disabled');
        $user = UserFactory::createOne();
        $keys = new E2eeKeys();
        $client = $this->plainJsonClient($user);

        $response = $client->request('POST', '/api/v1/e2ee/account', ['json' => ['publicKey' => $keys->accountPublicKey()]]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Encrypted direct messages are turned off on this server.', $response->toArray(false)['error']);

        $response = $client->request('POST', '/api/v1/e2ee/devices', ['json' => $keys->device((int) $user->getId())['body']]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Encrypted direct messages are turned off on this server.', $response->toArray(false)['error']);
    }

    public function testReplacementDeviceForExistingAccountAllowedWhenDisabled(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $this->registerDevice($client, $keys, $user);
        $this->patchMode('disabled');

        $response = $client->request('POST', '/api/v1/e2ee/devices', ['json' => $keys->device((int) $user->getId(), name: 'Desktop')['body']]);

        self::assertSame(201, $response->getStatusCode());
    }

    public function testRegistrationAllowedWhenRequired(): void
    {
        $this->storeMode('required');
        [$user, $keys, $client] = $this->userWithAccount();

        $this->registerDevice($client, $keys, $user);

        self::assertResponseStatusCodeSame(201);
    }

    public function testServerInfoExposesMode(): void
    {
        $info = static::createClient()->request('GET', '/api/v1/server-info', ['headers' => ['Accept' => 'application/json']])->toArray();
        self::assertSame('enabled', $info['encryptedDmsMode']);

        $this->patchMode('disabled');

        $info = static::createClient()->request('GET', '/api/v1/server-info', ['headers' => ['Accept' => 'application/json']])->toArray();
        self::assertSame('disabled', $info['encryptedDmsMode']);
    }

    public function testExistingDeviceListStillReadableWhenDisabled(): void
    {
        [$user, $keys, $client] = $this->userWithAccount();
        $device = $this->registerDevice($client, $keys, $user);
        $this->patchMode('disabled');

        $response = $client->request('GET', '/api/v1/e2ee/devices');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$device['body']['deviceId']], array_column($response->toArray()['items'], 'id'));
    }

    public function testInvalidModeValueRejected(): void
    {
        $response = $this->patchMode('sometimes');

        self::assertSame(422, $response->getStatusCode());
    }

    public function testRequiredRejectedUntilEnforcementShips(): void
    {
        $response = $this->patchMode('required');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Requiring encrypted direct messages is not available yet.', $response->toArray(false)['error']);
        $admin = UserFactory::new()->admin()->create();
        self::assertSame('enabled', $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/server-config')->toArray()['encryptedDmsMode']);
    }

    private function patchMode(string $mode): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $admin = UserFactory::new()->admin()->create();

        return $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['encryptedDmsMode' => $mode],
        ]);
    }

    private function storeMode(string $mode): void
    {
        $setting = new Setting(Settings::encryptedDmsMode()->key);
        $setting->setValue($mode);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($setting);
        $em->flush();
        $em->clear();
    }
}
