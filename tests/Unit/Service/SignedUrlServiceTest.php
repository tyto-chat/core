<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\MediaObject\SignedUrlService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SignedUrlServiceTest extends TestCase
{
    private const string KEY = '0123456789abcdef0123456789abcdef';

    private function buildService(int $defaultTtl = 60): SignedUrlService
    {
        /** @var SettingsServiceInterface&Stub $settings */
        $settings = self::createStub(SettingsServiceInterface::class);
        $settings->method('get')->willReturnCallback(static function (mixed $def) use ($defaultTtl): mixed {
            return match ($def->key) {
                Settings::mediaTokenTtlSeconds()->key => $defaultTtl,
                default => throw new \UnexpectedValueException('Unexpected setting key: '.$def->key),
            };
        });

        return new SignedUrlService(self::KEY, $settings);
    }

    public function testSignWithDefaultTtlVerifies(): void
    {
        $service = $this->buildService(60);
        $token = $service->sign('path/file.png');

        self::assertTrue($service->verify('path/file.png', $token));
    }

    public function testSignWithCustomTtlOverridesDefault(): void
    {
        $service = $this->buildService(60);
        $token = $service->sign('path/file.png', 3600);
        [, $expiry] = explode('.', $token);

        self::assertGreaterThan(time() + 1000, (int) $expiry);
    }

    public function testSignStableProducesSameTokenWithinBucket(): void
    {
        $service = $this->buildService();

        $first = $service->signStable('emoji/x.png', 7200);
        $second = $service->signStable('emoji/x.png', 7200);

        self::assertSame($first, $second);
    }

    public function testSignStableDifferentiatesPaths(): void
    {
        $service = $this->buildService();

        $a = $service->signStable('emoji/a.png', 7200);
        $b = $service->signStable('emoji/b.png', 7200);

        self::assertNotSame($a, $b);
    }

    public function testSignStableVerifies(): void
    {
        $service = $this->buildService();
        $token = $service->signStable('emoji/x.png', 7200);

        self::assertTrue($service->verify('emoji/x.png', $token));
    }

    public function testSignStableTokenIsValidForAtLeastTtl(): void
    {
        $service = $this->buildService();
        $token = $service->signStable('attachments/a.png', 600);

        [, $expiry] = explode('.', $token, 2);
        self::assertGreaterThanOrEqual(time() + 600, (int) $expiry);
        self::assertLessThan(time() + 1200 + 1, (int) $expiry);
    }

    public function testSignStableIsDeterministicWithinBucket(): void
    {
        $service = $this->buildService();
        self::assertSame(
            $service->signStable('attachments/a.png', 600),
            $service->signStable('attachments/a.png', 600),
        );
    }

    public function testVerifyRejectsTamperedToken(): void
    {
        $service = $this->buildService();
        $token = $service->sign('safe/file.png');

        self::assertFalse($service->verify('attacker/file.png', $token));
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        $service = $this->buildService();
        $expiredToken = hash_hmac('sha256', 'p:1.0', self::KEY).'.1';

        self::assertFalse($service->verify('p', $expiredToken));
    }

    public function testConstructorRejectsShortKey(): void
    {
        $settings = self::createStub(SettingsServiceInterface::class);
        $this->expectException(\InvalidArgumentException::class);
        new SignedUrlService('too-short', $settings);
    }
}
