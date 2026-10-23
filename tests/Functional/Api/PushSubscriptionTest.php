<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PushSubscriptionTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function repo(): PushSubscriptionRepository
    {
        $repo = static::getContainer()->get(PushSubscriptionRepository::class);
        \assert($repo instanceof PushSubscriptionRepository);

        return $repo;
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private function payload(string $endpoint = 'https://push.example.com/abc'): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'pubkey', 'auth' => 'authsecret']];
    }

    public function testSubscribeCreatesRow(): void
    {
        $user = UserFactory::createOne();
        $this->jsonClient($user)->request('POST', '/api/v1/me/push-subscriptions', [
            'json' => $this->payload(),
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($this->repo()->findOneByEndpoint('https://push.example.com/abc'));
    }

    public function testSubscribeIsIdempotentPerEndpoint(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        $client->request('POST', '/api/v1/me/push-subscriptions', ['json' => $this->payload()]);
        $client->request('POST', '/api/v1/me/push-subscriptions', ['json' => $this->payload()]);

        self::assertSame(1, $this->repo()->countByUser($user));
    }

    public function testSubscriptionsPerUserAreCappedKeepingTheNewest(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        for ($i = 0; $i <= 10; ++$i) {
            $client->request('POST', '/api/v1/me/push-subscriptions', ['json' => $this->payload('https://push.example.com/d'.$i)]);
            self::assertResponseStatusCodeSame(201);
        }

        self::assertSame(10, $this->repo()->countByUser($user));
        self::assertNull($this->repo()->findOneByEndpoint('https://push.example.com/d0'));
        self::assertNotNull($this->repo()->findOneByEndpoint('https://push.example.com/d10'));
    }

    public function testCapNeverDropsTheDeviceBeingRegistered(): void
    {
        $other = UserFactory::createOne();
        $this->jsonClient($other)->request('POST', '/api/v1/me/push-subscriptions', ['json' => $this->payload('https://push.example.com/shared')]);
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        for ($i = 1; $i <= 10; ++$i) {
            $client->request('POST', '/api/v1/me/push-subscriptions', ['json' => $this->payload('https://push.example.com/d'.$i)]);
        }

        $client->request('POST', '/api/v1/me/push-subscriptions', ['json' => $this->payload('https://push.example.com/shared')]);

        self::assertSame(10, $this->repo()->countByUser($user));
        self::assertNotNull($this->repo()->findOneByEndpoint('https://push.example.com/shared'));
        self::assertNull($this->repo()->findOneByEndpoint('https://push.example.com/d1'));
    }

    public function testSubscribeRejectsIncompletePayload(): void
    {
        $user = UserFactory::createOne();
        // Missing keys → DTO validation fails (API Platform returns 422).
        $this->jsonClient($user)->request('POST', '/api/v1/me/push-subscriptions', [
            'json' => ['endpoint' => 'https://push.example.com/x'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnsubscribeRemovesRow(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);
        $client->request('POST', '/api/v1/me/push-subscriptions', ['json' => $this->payload()]);

        $client->request('DELETE', '/api/v1/me/push-subscriptions?endpoint='.urlencode('https://push.example.com/abc'));

        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->repo()->findOneByEndpoint('https://push.example.com/abc'));
    }

    public function testUnsubscribeDoesNotTouchOtherUsersSubscription(): void
    {
        $owner = UserFactory::createOne();
        $other = UserFactory::createOne();
        $this->jsonClient($owner)->request('POST', '/api/v1/me/push-subscriptions', [
            'json' => $this->payload(),
        ]);

        // Another user trying to delete the same endpoint must not remove it.
        $this->jsonClient($other)->request('DELETE', '/api/v1/me/push-subscriptions?endpoint='.urlencode('https://push.example.com/abc'));

        self::assertResponseStatusCodeSame(204);
        self::assertNotNull($this->repo()->findOneByEndpoint('https://push.example.com/abc'));
    }

    public function testRequiresAuthentication(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/me/push-subscriptions', [
            'json' => $this->payload(),
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
