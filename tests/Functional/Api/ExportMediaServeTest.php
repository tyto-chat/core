<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Service\Gdpr\ExportDownloadLimiter;
use App\Service\Gdpr\ExportDownloadLimiterInterface;
use App\Service\MediaObject\SignedUrlServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemoryExportDownloadLimiter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Exercises /export-media/{token}/{filename} — the dedicated route for GDPR
 * export attachment URLs. Unlike /media/, this route caps redemptions per
 * token and returns 410 on expiry (vs 404 for the regular media route).
 */
class ExportMediaServeTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function minimalPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    /** @return string the attachment file's relative path (used as the signed-URL subject) */
    private function uploadAttachment(\App\Entity\User $user, \App\Entity\Community $community, \App\Entity\Channel $channel): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'em_test_');
        file_put_contents((string) $tmp, $this->minimalPng());
        $file = new UploadedFile((string) $tmp, 'test.png', 'image/png', null, true);

        $response = $this->uploadClient($user)->request(
            'POST',
            '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier().'/attachments',
            ['extra' => ['files' => ['file' => $file]]],
        );
        self::assertResponseStatusCodeSame(201);

        // contentUrl carries the regular /media/ URL; we want the underlying
        // filePath the signed URL is bound to.
        $contentUrl = (string) $response->toArray()['contentUrl'];
        $segments = explode('/', (string) parse_url($contentUrl, PHP_URL_PATH));

        return $segments[3]; // ['', 'media', token, filename]
    }

    private function sign(string $relativePath, int $ttlSeconds): string
    {
        $signer = static::getContainer()->get(SignedUrlServiceInterface::class);
        \assert($signer instanceof SignedUrlServiceInterface);

        return $signer->sign($relativePath, $ttlSeconds);
    }

    private function resetLimiter(): void
    {
        // Access via the interface alias (registered in services_test.yaml).
        $limiter = static::getContainer()->get(ExportDownloadLimiterInterface::class);
        \assert($limiter instanceof InMemoryExportDownloadLimiter);
        $limiter->reset();
    }

    private function bootScenario(): string
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('em-c')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()
            ->inCommunity($community)
            ->with(['identifier' => 'em-ch', 'allowAttachments' => true])
            ->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        return $this->uploadAttachment($user, $community, $channel);
    }

    public function testValidTokenServesAttachment(): void
    {
        $this->resetLimiter();
        $filename = $this->bootScenario();
        $token = $this->sign($filename, 3600);

        static::createClient()->request('GET', '/export-media/'.$token.'/'.$filename);

        self::assertResponseStatusCodeSame(200);
    }

    public function testExpiredTokenReturns410(): void
    {
        $this->resetLimiter();
        $filename = $this->bootScenario();

        // Build a manually-signed token with a past expiry — verify() rejects
        // it, controller maps to 410 Gone.
        $expiry = time() - 1;
        $signingKey = (string) ($_ENV['APP_MEDIA_SIGNING_KEY'] ?? '');
        $hmac = hash_hmac('sha256', $filename.':'.$expiry, $signingKey);
        $expiredToken = $hmac.'.'.$expiry;

        static::createClient()->request('GET', '/export-media/'.$expiredToken.'/'.$filename);

        self::assertResponseStatusCodeSame(410);
    }

    public function testTamperedTokenReturns410(): void
    {
        $this->resetLimiter();
        $filename = $this->bootScenario();
        $token = $this->sign($filename, 3600);
        $token[0] = 'a' === $token[0] ? '0' : 'a';

        static::createClient()->request('GET', '/export-media/'.$token.'/'.$filename);

        self::assertResponseStatusCodeSame(410);
    }

    public function testDownloadCapTriggers429AfterMaxRedemptions(): void
    {
        $this->resetLimiter();
        $filename = $this->bootScenario();
        $token = $this->sign($filename, 3600);

        $client = static::createClient();
        // Symfony's KernelBrowser reboots the kernel between requests by
        // default, which would wipe the in-memory limiter's counters and let
        // every redemption sail through. Disable reboot so the counter
        // accumulates the way it does behind a real persistent server.
        $client->disableReboot();
        for ($i = 0; $i < ExportDownloadLimiter::MAX_DOWNLOADS; ++$i) {
            $client->request('GET', '/export-media/'.$token.'/'.$filename);
            self::assertResponseStatusCodeSame(200, sprintf('redemption #%d should succeed', $i + 1));
        }

        // One past the cap → 429.
        $client->request('GET', '/export-media/'.$token.'/'.$filename);
        self::assertResponseStatusCodeSame(429);
    }
}
