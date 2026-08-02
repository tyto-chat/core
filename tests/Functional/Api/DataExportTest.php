<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\DataExportRequest;
use App\Entity\User;
use App\Service\Gdpr\DataExportServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\DataExportRequestFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class DataExportTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testStatusNoActiveExportReturnsFlagFalse(): void
    {
        $user = UserFactory::createOne();

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/me/data-export');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['pending' => false], $response->toArray());
    }

    public function testStatusReturnsActiveQueuedRequest(): void
    {
        $user = UserFactory::createOne();
        DataExportRequestFactory::createOne([
            'user' => $user,
            'status' => DataExportRequest::STATUS_QUEUED,
        ]);

        $response = $this->plainJsonClient($user)->request('GET', '/api/v1/me/data-export');

        self::assertResponseStatusCodeSame(200);
        $body = $response->toArray();
        self::assertTrue($body['pending']);
        self::assertSame(DataExportRequest::STATUS_QUEUED, $body['status']);
    }

    public function testRequestQueuesNewExport(): void
    {
        $user = UserFactory::createOne();

        $response = $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();
        self::assertTrue($body['pending']);
        // GenerateDataExportMessage dispatch synchronously in tests (in-memory
        // transport) — should already be ready.
        self::assertSame(DataExportRequest::STATUS_READY, $body['status']);
        self::assertNotNull($body['downloadUrl']);
    }

    public function testRequestRejectsWhileExistingIsInFlight(): void
    {
        $user = UserFactory::createOne();
        DataExportRequestFactory::createOne([
            'user' => $user,
            'status' => DataExportRequest::STATUS_PROCESSING,
            'requestedAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        // 24h cooldown trips first — also a valid block. Either way: not 201.
        $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');

        $status = $this->latestStatusCode();
        self::assertContains($status, [409, 429], 'Expected 409 (in-flight) or 429 (cooldown), got '.$status);
    }

    public function testRequestRejectedByCooldown(): void
    {
        $user = UserFactory::createOne();
        DataExportRequestFactory::createOne([
            'user' => $user,
            'status' => DataExportRequest::STATUS_EXPIRED,
            'requestedAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');

        self::assertResponseStatusCodeSame(429);
    }

    public function testRequestAllowedAfterCooldown(): void
    {
        $user = UserFactory::createOne();
        DataExportRequestFactory::createOne([
            'user' => $user,
            'status' => DataExportRequest::STATUS_EXPIRED,
            'requestedAt' => new \DateTimeImmutable('-2 days'),
        ]);

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');

        self::assertResponseStatusCodeSame(201);
    }

    public function testDownloadServesReadyArchive(): void
    {
        $user = UserFactory::createOne();
        $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $request = $em->getRepository(DataExportRequest::class)
            ->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($request?->getDownloadToken());

        $client = $this->plainJsonClient($user);
        $client->request('GET', '/api/v1/me/data-export/download?token='.$request->getDownloadToken());

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', 'application/zip');
    }

    public function testDownloadRejectsOtherUsersToken(): void
    {
        $alice = UserFactory::createOne();
        $bob = UserFactory::createOne();
        $this->plainJsonClient($alice)->request('POST', '/api/v1/me/data-export');
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $request = $em->getRepository(DataExportRequest::class)
            ->findOneBy(['user' => $alice->getId()]);
        self::assertNotNull($request?->getDownloadToken());

        $this->plainJsonClient($bob)->request(
            'GET',
            '/api/v1/me/data-export/download?token='.$request->getDownloadToken(),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadRejectsUnknownToken(): void
    {
        $user = UserFactory::createOne();

        $this->plainJsonClient($user)->request(
            'GET',
            '/api/v1/me/data-export/download?token=deadbeef',
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testZipContainsCollectorSectionsForUser(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('c-export')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'g-export'])->create();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        MessageFactory::new()->inPage($page)->byUser($user)->withText('hello world')->create();

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $request = $em->getRepository(DataExportRequest::class)
            ->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($request);

        $dir = $_ENV['APP_EXPORT_DIR'];
        $absolute = $dir.'/'.$request->getFilePath();
        self::assertFileExists($absolute);

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($absolute));
        try {
            $files = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $files[] = $zip->getNameIndex($i);
            }
            self::assertContains('README.txt', $files);
            self::assertContains('profile.json', $files);
            self::assertContains('messages.json', $files);
            self::assertContains('conversations.json', $files);
            self::assertContains('attachments.json', $files);
            self::assertContains('reactions.json', $files);
            self::assertContains('moderation.json', $files);
            self::assertContains('notifications.json', $files);

            $profile = json_decode((string) $zip->getFromName('profile.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($user->getEmail(), $profile['email']);

            $messages = json_decode((string) $zip->getFromName('messages.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(1, $messages);
            self::assertSame('hello world', $messages[0]['history'][0]['body']);
        } finally {
            $zip->close();
        }
    }

    public function testReadmeUsesUserLocale(): void
    {
        $user = UserFactory::createOne();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->getRepository(User::class)->find($user->getId())?->setLocale('pl');
        $em->flush();

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');
        self::assertResponseStatusCodeSame(201);

        $request = $em->getRepository(DataExportRequest::class)
            ->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($request);

        $dir = $_ENV['APP_EXPORT_DIR'];
        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($dir.'/'.$request->getFilePath()));
        try {
            $readme = (string) $zip->getFromName('README.txt');
            self::assertStringContainsString('Eksport danych Tyto', $readme);
            self::assertStringNotContainsString('Tyto data export', $readme);
        } finally {
            $zip->close();
        }
    }

    public function testReadmeFallsBackToEnglishForUnsupportedLocale(): void
    {
        $user = UserFactory::createOne();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->getRepository(User::class)->find($user->getId())?->setLocale('xx');
        $em->flush();

        $this->plainJsonClient($user)->request('POST', '/api/v1/me/data-export');
        self::assertResponseStatusCodeSame(201);

        $request = $em->getRepository(DataExportRequest::class)
            ->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($request);

        $dir = $_ENV['APP_EXPORT_DIR'];
        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($dir.'/'.$request->getFilePath()));
        try {
            $readme = (string) $zip->getFromName('README.txt');
            self::assertStringContainsString('Tyto data export', $readme);
        } finally {
            $zip->close();
        }
    }

    public function testExpireOldDeletesPastTtl(): void
    {
        $user = UserFactory::createOne();
        DataExportRequestFactory::createOne([
            'user' => $user,
            'status' => DataExportRequest::STATUS_READY,
            'requestedAt' => new \DateTimeImmutable('-10 days'),
            'readyAt' => new \DateTimeImmutable('-9 days'),
            'expiresAt' => new \DateTimeImmutable('-2 days'),
        ]);

        $service = static::getContainer()->get(DataExportServiceInterface::class);
        \assert($service instanceof DataExportServiceInterface);

        self::assertSame(1, $service->expireOld());
    }

    private function latestStatusCode(): int
    {
        $response = static::getClient()->getResponse();
        \assert($response instanceof \Symfony\Component\HttpFoundation\Response);

        return $response->getStatusCode();
    }

    /**
     * Override because we need each test to run with the messenger handlers
     * registered. Default Messenger transport in services_test.yaml is
     * 'sync', which dispatches synchronously.
     */
    protected function setUp(): void
    {
        // Ensure export dir exists + is writable in tests.
        $dir = sys_get_temp_dir().'/tyto-test-exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $_ENV['APP_EXPORT_DIR'] = $dir;
        $_SERVER['APP_EXPORT_DIR'] = $dir;
        parent::setUp();
    }
}
