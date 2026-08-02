<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Mailer\DbConfiguredTransport;
use App\Service\Security\SecretBoxInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SmtpConfigTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testSmtpRoundTripHidesPassword(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $client = $this->plainJsonClient($admin);

        $client->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'smtpHost' => 'smtp.example.com',
                'smtpPort' => 587,
                'smtpUsername' => 'u',
                'smtpPassword' => 'secret',
                'smtpEncryption' => 'tls',
                'smtpFromEmail' => 'no-reply@example.com',
                'smtpFromName' => 'Tyto',
            ],
        ]);
        self::assertResponseStatusCodeSame(200);

        $response = $client->request('GET', '/api/v1/admin/server-config');
        self::assertResponseStatusCodeSame(200);

        $body = $response->toArray();
        self::assertSame('smtp.example.com', $body['smtpHost']);
        self::assertSame(587, $body['smtpPort']);
        self::assertSame('u', $body['smtpUsername']);
        self::assertSame('tls', $body['smtpEncryption']);
        self::assertSame('no-reply@example.com', $body['smtpFromEmail']);
        self::assertSame('Tyto', $body['smtpFromName']);
        self::assertTrue($body['smtpPasswordSet']);

        // The raw secret must never leak into any serialized response.
        self::assertStringNotContainsString('secret', $response->getContent());
    }

    public function testOmittingPasswordKeepsIt(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $client = $this->plainJsonClient($admin);

        $client->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'smtpHost' => 'smtp.example.com',
                'smtpPassword' => 'secret',
            ],
        ]);
        self::assertResponseStatusCodeSame(200);

        $client->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['smtpFromName' => 'New'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $body = $client->request('GET', '/api/v1/admin/server-config')->toArray();
        self::assertSame('New', $body['smtpFromName']);
        self::assertTrue($body['smtpPasswordSet']);
    }

    public function testInvalidPortRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['smtpPort' => 70000],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidFromEmailRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['smtpFromEmail' => 'bad'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTransportFallsBackWhenDbSmtpUnsetThenBuildsEsmtp(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $client = $this->plainJsonClient($admin);

        $container = static::getContainer();
        $settingsService = $container->get(SettingsServiceInterface::class);
        $secretBox = $container->get(SecretBoxInterface::class);
        $inner = self::createStub(TransportInterface::class);

        // No DB SMTP configured yet -> buildTransport() returns null (env fallback).
        $transport = new DbConfiguredTransport($inner, $settingsService, $secretBox);
        self::assertNull($transport->buildTransport());

        $client->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'smtpHost' => 'smtp.example.com',
                'smtpPort' => 587,
                'smtpUsername' => 'u',
                'smtpPassword' => 'secret',
                'smtpEncryption' => 'tls',
            ],
        ]);
        self::assertResponseStatusCodeSame(200);

        $settingsService = $container->get(SettingsServiceInterface::class);
        $secretBox = $container->get(SecretBoxInterface::class);
        $transport = new DbConfiguredTransport($inner, $settingsService, $secretBox);
        $built = $transport->buildTransport();
        self::assertInstanceOf(EsmtpTransport::class, $built);
        self::assertStringContainsString('smtp.example.com', (string) $built);
    }
}
