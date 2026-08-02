<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Webhook;
use App\Entity\WebhookDelivery;
use App\Enum\Webhook\WebhookDeliveryStatus;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WebhookFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class WebhookAdminTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testNonAdminGetsForbidden(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request('GET', '/api/v1/admin/webhooks');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousGets401(): void
    {
        static::createClient()->request('GET', '/api/v1/admin/webhooks');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminCanListCreateGetDelete(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/webhooks');
        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('rows', $body);
        self::assertCount(0, $body['rows']);

        $client = $this->plainJsonClient($admin);
        $createResponse = $client->request('POST', '/api/v1/admin/webhooks', [
            'json' => [
                'name' => 'Test Hook',
                'url' => 'https://example.test/hook',
                'triggerKey' => 'message.created',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $createResponse->toArray();
        self::assertArrayHasKey('id', $created);
        self::assertArrayHasKey('secret', $created);
        self::assertIsString($created['secret']);
        self::assertNotEmpty($created['secret']);
        self::assertArrayNotHasKey('secret', $created['webhook'] ?? []);
        $webhookId = $created['id'];

        $getResponse = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/webhooks/'.$webhookId);
        self::assertResponseStatusCodeSame(200);
        $detail = $getResponse->toArray();
        self::assertSame($webhookId, $detail['id']);
        self::assertSame('Test Hook', $detail['name']);
        self::assertArrayNotHasKey('secret', $detail);

        $listResponse = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/webhooks');
        self::assertResponseStatusCodeSame(200);
        $list = $listResponse->toArray();
        self::assertCount(1, $list['rows']);
        self::assertArrayNotHasKey('secret', $list['rows'][0]);
        self::assertArrayHasKey('pendingCount', $list['rows'][0]);

        $patchResponse = $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/webhooks/'.$webhookId, [
            'json' => ['name' => 'Updated Hook'],
        ]);
        self::assertResponseStatusCodeSame(200);
        $patched = $patchResponse->toArray();
        self::assertSame('Updated Hook', $patched['name']);

        $this->plainJsonClient($admin)->request('DELETE', '/api/v1/admin/webhooks/'.$webhookId);
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        self::assertNull($em->getRepository(Webhook::class)->find($webhookId));
    }

    public function testCreateRecordsActingAdminAsCreatedBy(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $createResponse = $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/webhooks', [
            'json' => [
                'name' => 'Creator Hook',
                'url' => 'https://example.test/hook',
                'triggerKey' => 'message.created',
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $webhookId = $createResponse->toArray()['id'];

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        $persisted = $em->getRepository(Webhook::class)->find($webhookId);
        self::assertNotNull($persisted);
        self::assertNotNull($persisted->getCreatedBy());
        self::assertSame($admin->getId(), $persisted->getCreatedBy()->getId());
    }

    public function testCreateRejectsUnknownTrigger(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/webhooks', [
            'json' => [
                'name' => 'Bad Hook',
                'url' => 'https://example.test/hook',
                'triggerKey' => 'not.a.real.trigger',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/webhooks', [
            'json' => ['name' => 'No URL or trigger'],
        ]);

        // DTO-level Assert constraints now reject at the API boundary → 422.
        self::assertResponseStatusCodeSame(422);
    }

    public function testTriggersEndpointListsRegistry(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/webhooks/triggers');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('triggers', $body);

        $keys = array_column($body['triggers'], 'key');
        self::assertContains('message.created', $keys);
        self::assertContains('message.replied', $keys);
        self::assertContains('reaction.added', $keys);
        self::assertContains('moderation.action', $keys);
        self::assertCount(4, $body['triggers']);
    }

    public function testDeliveriesEndpointPaginates(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $webhook = WebhookFactory::createOne();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $webhookEntity = $em->getRepository(Webhook::class)->find($webhook->getId());
        self::assertNotNull($webhookEntity);

        for ($i = 0; $i < 3; ++$i) {
            $d = new WebhookDelivery(
                $webhookEntity,
                'message.created',
                null,
                ['event' => 'message.created'],
                WebhookDeliveryStatus::Success,
            );
            $em->persist($d);
        }
        $em->flush();

        $response = $this->plainJsonClient($admin)->request(
            'GET',
            '/api/v1/admin/webhooks/'.$webhook->getId().'/deliveries?page=1&perPage=2',
        );
        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('rows', $body);
        self::assertCount(2, $body['rows']);
    }

    public function testRegenerateSecretReturnsNewSecret(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $webhook = WebhookFactory::createOne();
        $oldSecret = $webhook->getSecret();

        $response = $this->plainJsonClient($admin)->request(
            'POST',
            '/api/v1/admin/webhooks/'.$webhook->getId().'/regenerate-secret',
        );

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('secret', $body);
        self::assertIsString($body['secret']);
        self::assertNotEmpty($body['secret']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $fresh = $em->getRepository(Webhook::class)->find($webhook->getId());
        self::assertNotNull($fresh);
        self::assertNotSame($oldSecret, $fresh->getSecret());
        self::assertSame($fresh->getSecret(), $body['secret']);
    }

    public function testTestEndpointDispatchesDelivery(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $webhook = WebhookFactory::createOne();

        $response = $this->plainJsonClient($admin)->request(
            'POST',
            '/api/v1/admin/webhooks/'.$webhook->getId().'/test',
        );

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertArrayHasKey('deliveryId', $body);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        self::assertNotNull($em->getRepository(WebhookDelivery::class)->find($body['deliveryId']));
    }

    public function testGetUnknownWebhookReturns404(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('GET', '/api/v1/admin/webhooks/99999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminCannotUpdateWebhook(): void
    {
        $user = UserFactory::createOne();
        $webhook = WebhookFactory::createOne();

        $this->plainJsonClient($user)->request('PATCH', '/api/v1/admin/webhooks/'.$webhook->getId(), [
            'json' => ['name' => 'hijack'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAdminCannotDeleteWebhook(): void
    {
        $user = UserFactory::createOne();
        $webhook = WebhookFactory::createOne();

        $this->plainJsonClient($user)->request('DELETE', '/api/v1/admin/webhooks/'.$webhook->getId());

        self::assertResponseStatusCodeSame(403);
    }
}
