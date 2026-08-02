<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Repository\UserRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class EmailNotificationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testToggleEnablesAndPinsLocale(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => false, 'locale' => 'en']);

        $client = $this->jsonClient($user);
        $client->request('POST', '/api/v1/me/email-notifications', [
            'json' => ['enabled' => true],
            'headers' => ['Accept-Language' => 'pl'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->toArray()['emailNotifications']);

        $repo = static::getContainer()->get(UserRepository::class);
        \assert($repo instanceof UserRepository);
        $fresh = $repo->find($user->getId());
        \assert(null !== $fresh);
        self::assertTrue($fresh->isEmailNotifications());
        self::assertSame('pl', $fresh->getLocale());
    }

    public function testToggleDisables(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => true]);
        $client = $this->jsonClient($user);
        $client->request('POST', '/api/v1/me/email-notifications', ['json' => ['enabled' => false]]);

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->toArray()['emailNotifications']);
    }

    public function testRejectsMissingFlag(): void
    {
        $user = UserFactory::createOne();
        $this->jsonClient($user)->request('POST', '/api/v1/me/email-notifications', [
            'json' => ['nope' => true],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuth(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/me/email-notifications', [
            'json' => ['enabled' => true],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testEmailNotificationsExposedOnMe(): void
    {
        $user = UserFactory::createOne(['emailNotifications' => true]);
        $client = $this->jsonClient($user);
        $client->request('GET', '/api/v1/me');
        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->toArray()['emailNotifications']);
    }
}
