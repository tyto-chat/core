<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\IpReputation;

use App\Enum\IpReputation\IpReputationVerdict;
use App\Service\IpReputation\IpReputationAllowlist;
use App\Service\IpReputation\StopForumSpamIpReputationService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\SettingDef;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StopForumSpamIpReputationServiceTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function settings(array $overrides = []): SettingsServiceInterface
    {
        $stub = self::createStub(SettingsServiceInterface::class);
        $stub->method('get')->willReturnCallback(
            fn (SettingDef $def) => $overrides[$def->key] ?? $def->default,
        );

        return $stub;
    }

    private function service(
        MockHttpClient $http,
        SettingsServiceInterface $settings,
        ?ClientInterface $redis = null,
    ): StopForumSpamIpReputationService {
        $redis ??= self::createStub(ClientInterface::class);

        return new StopForumSpamIpReputationService($settings, $http, $redis, new IpReputationAllowlist());
    }

    public function testDisabledReturnsCleanWithoutHttp(): void
    {
        $http = new MockHttpClient(fn () => throw new \LogicException('no HTTP expected'));
        $svc = $this->service($http, $this->settings());
        self::assertSame(IpReputationVerdict::Clean, $svc->check('1.2.3.4', 'a@b.c'));
    }

    public function testAllowlistedSkipsHttp(): void
    {
        $http = new MockHttpClient(fn () => throw new \LogicException('no HTTP expected'));
        $svc = $this->service($http, $this->settings([
            'ipReputationEnabled' => true,
            'ipReputationAllowlist' => "a@b.c\n",
        ]));
        self::assertSame(IpReputationVerdict::Clean, $svc->check('1.2.3.4', 'a@b.c'));
    }

    public function testFlaggedAboveThreshold(): void
    {
        $body = (string) json_encode(['success' => 1, 'ip' => ['appears' => 1, 'confidence' => 90.5, 'frequency' => 255]]);
        $http = new MockHttpClient(new MockResponse($body));
        $svc = $this->service($http, $this->settings(['ipReputationEnabled' => true]));
        self::assertSame(IpReputationVerdict::Flagged, $svc->check('1.2.3.4', 'a@b.c'));
    }

    public function testCleanBelowThreshold(): void
    {
        $body = (string) json_encode(['success' => 1, 'ip' => ['appears' => 1, 'confidence' => 10.0], 'emailhash' => ['appears' => 0]]);
        $http = new MockHttpClient(new MockResponse($body));
        $svc = $this->service($http, $this->settings(['ipReputationEnabled' => true]));
        self::assertSame(IpReputationVerdict::Clean, $svc->check('1.2.3.4', 'a@b.c'));
    }

    public function testSendsEmailHashNeverRawEmail(): void
    {
        $seenBody = '';
        $http = new MockHttpClient(function ($method, $url, $options) use (&$seenBody) {
            $seenBody = $options['body'] ?? '';
            self::assertSame('POST', $method);

            return new MockResponse((string) json_encode(['success' => 1]));
        });
        $svc = $this->service($http, $this->settings(['ipReputationEnabled' => true]));
        $svc->check('1.2.3.4', 'Bob@Example.com');
        self::assertStringContainsString('emailhash='.md5('bob@example.com'), $seenBody);
        self::assertStringNotContainsString('Example.com', $seenBody);
    }

    public function testTransportErrorFailsOpen(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'connection refused']));
        $svc = $this->service($http, $this->settings(['ipReputationEnabled' => true]));
        self::assertSame(IpReputationVerdict::Unavailable, $svc->check('1.2.3.4', 'a@b.c'));
    }

    public function testCacheHitSkipsHttp(): void
    {
        $redis = self::createStub(ClientInterface::class);
        $redis->method('__call')->willReturnCallback(
            fn (string $cmd) => 'get' === $cmd ? 'flagged' : null,
        );
        $http = new MockHttpClient(fn () => throw new \LogicException('no HTTP expected'));
        $svc = $this->service($http, $this->settings(['ipReputationEnabled' => true]), $redis);
        self::assertSame(IpReputationVerdict::Flagged, $svc->check('1.2.3.4', 'a@b.c'));
    }
}
