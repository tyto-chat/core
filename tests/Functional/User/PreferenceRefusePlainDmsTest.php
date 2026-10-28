<?php

declare(strict_types=1);

namespace App\Tests\Functional\User;

use App\Entity\Setting;
use App\Entity\UserPreference;
use App\Settings\Settings;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PreferenceRefusePlainDmsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private const array MERGE_PATCH = ['headers' => ['Content-Type' => 'application/merge-patch+json']];

    public function testRefusePlainDmsDefaultsFalse(): void
    {
        $user = UserFactory::createOne();

        $data = $this->jsonClient($user)->request('GET', '/api/v1/me/preferences')->toArray();

        self::assertArrayHasKey('refusePlainDms', $data);
        self::assertNull($data['refusePlainDms']);
        self::assertFalse((new UserPreference($user))->refusesPlainDms());
    }

    public function testRefusePlainDmsRoundtripsThroughMePreferences(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);

        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['refusePlainDms' => true]]);
        self::assertResponseIsSuccessful();

        self::assertTrue($client->request('GET', '/api/v1/me/preferences')->toArray()['refusePlainDms']);
        $stored = $this->preferenceOf($user->getId());
        self::assertTrue($stored->refusesPlainDms());
    }

    public function testRefusePlainDmsCannotBeTurnedOnWhenDisabled(): void
    {
        $this->storeMode('disabled');
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);

        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['refusePlainDms' => true]]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($client->request('GET', '/api/v1/me/preferences')->toArray()['refusePlainDms']);
    }

    public function testRefusePlainDmsCanBeTurnedOffWhenDisabled(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['refusePlainDms' => true]]);
        self::assertResponseIsSuccessful();
        $this->storeMode('disabled');

        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['refusePlainDms' => false]]);
        self::assertResponseIsSuccessful();
        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['theme' => 'dark']]);
        self::assertResponseIsSuccessful();

        self::assertFalse($client->request('GET', '/api/v1/me/preferences')->toArray()['refusePlainDms']);
    }

    public function testOtherPreferencesStillSaveWhenRefusalIsOnAndModeDisabled(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['refusePlainDms' => true]]);
        $this->storeMode('disabled');

        $client->request('PATCH', '/api/v1/me/preferences', [...self::MERGE_PATCH, 'json' => ['theme' => 'dark']]);

        self::assertResponseIsSuccessful();
    }

    private function preferenceOf(int $userId): UserPreference
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $pref = $em->getRepository(UserPreference::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(UserPreference::class, $pref);

        return $pref;
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
