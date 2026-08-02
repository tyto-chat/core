<?php

declare(strict_types=1);

namespace App\Tests\Integration\IpReputation;

use App\Enum\IpReputation\IpReputationVerdict;
use App\Service\IpReputation\IpReputationAllowlist;
use App\Service\IpReputation\StopForumSpamIpReputationService;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\SettingDef;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Real Redis verdict cache + mocked StopForumSpam HTTP endpoint. The cache
 * behaviour is the part unit tests cannot cover: verdicts must be served
 * from Redis without a second HTTP call, and Unavailable must never be
 * cached (a transient outage must not stick for 24h).
 */
#[AllowMockObjectsWithoutExpectations]
class StopForumSpamIpReputationServiceTest extends TestCase
{
    private const IP = '198.51.100.77';
    private const EMAIL = 'itest-reputation@example.com';

    private Client $redis;
    private string $cacheKey;

    /** @var array<string, mixed> */
    private array $settings = [];

    #[\Override]
    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_DSN'] ?? $_SERVER['REDIS_DSN'] ?? null;
        self::assertIsString($dsn, 'REDIS_DSN must be set for integration tests.');

        $this->redis = new Client($dsn, ['exceptions' => true]);

        $this->settings = [
            'ipReputationEnabled' => true,
            'ipReputationEndpoint' => 'https://sfs.example.test',
            'ipReputationConfidenceMin' => 75,
            'ipReputationCheckUsername' => false,
            'ipReputationAllowlist' => '',
        ];

        $emailHash = md5(strtolower(trim(self::EMAIL)));
        $this->cacheKey = 'ip_reputation:'.sha1(self::IP.'|'.$emailHash.'|');
        $this->redis->del([$this->cacheKey]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->redis->del([$this->cacheKey]);
    }

    private function buildService(MockHttpClient $http): StopForumSpamIpReputationService
    {
        $settingsService = $this->createMock(SettingsServiceInterface::class);
        $settingsService->method('get')->willReturnCallback(
            fn (SettingDef $def): mixed => $this->settings[$def->key] ?? $def->default,
        );

        return new StopForumSpamIpReputationService(
            $settingsService,
            $http,
            $this->redis,
            new IpReputationAllowlist(),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function jsonResponse(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode($payload, \JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    public function testFlaggedVerdictIsCachedInRedisAndServedWithoutASecondHttpCall(): void
    {
        $http = new MockHttpClient([self::jsonResponse([
            'success' => 1,
            'ip' => ['appears' => 1, 'confidence' => 99.5],
        ])]);
        $service = $this->buildService($http);

        self::assertSame(IpReputationVerdict::Flagged, $service->check(self::IP, self::EMAIL));
        self::assertSame('flagged', $this->redis->get($this->cacheKey));
        self::assertGreaterThan(0, (int) $this->redis->ttl($this->cacheKey));

        // Second call: the MockHttpClient queue is exhausted — any request would throw.
        self::assertSame(IpReputationVerdict::Flagged, $service->check(self::IP, self::EMAIL));
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testCleanVerdictIsCachedToo(): void
    {
        $http = new MockHttpClient([self::jsonResponse([
            'success' => 1,
            'ip' => ['appears' => 0],
            'emailhash' => ['appears' => 0],
        ])]);
        $service = $this->buildService($http);

        self::assertSame(IpReputationVerdict::Clean, $service->check(self::IP, self::EMAIL));
        self::assertSame('clean', $this->redis->get($this->cacheKey));
    }

    public function testUnavailableIsNeverCached(): void
    {
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 503]),
            self::jsonResponse(['success' => 1, 'ip' => ['appears' => 1, 'confidence' => 99.5]]),
        ]);
        $service = $this->buildService($http);

        self::assertSame(IpReputationVerdict::Unavailable, $service->check(self::IP, self::EMAIL));
        self::assertNull($this->redis->get($this->cacheKey), 'A transient outage must not stick for 24h.');

        self::assertSame(IpReputationVerdict::Flagged, $service->check(self::IP, self::EMAIL));
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testConfidenceBelowMinimumIsClean(): void
    {
        $http = new MockHttpClient([self::jsonResponse([
            'success' => 1,
            'ip' => ['appears' => 1, 'confidence' => 50.0],
        ])]);

        self::assertSame(IpReputationVerdict::Clean, $this->buildService($http)->check(self::IP, self::EMAIL));
    }

    public function testDisabledSettingSkipsHttpAndCache(): void
    {
        $this->settings['ipReputationEnabled'] = false;
        $http = new MockHttpClient([]);

        self::assertSame(IpReputationVerdict::Clean, $this->buildService($http)->check(self::IP, self::EMAIL));
        self::assertSame(0, $http->getRequestsCount());
        self::assertNull($this->redis->get($this->cacheKey));
    }

    public function testAllowlistedIpSkipsHttpAndCache(): void
    {
        $this->settings['ipReputationAllowlist'] = self::IP;
        $http = new MockHttpClient([]);

        self::assertSame(IpReputationVerdict::Clean, $this->buildService($http)->check(self::IP, self::EMAIL));
        self::assertSame(0, $http->getRequestsCount());
    }

    public function testPoisonedCacheValueFallsThroughToAFreshQuery(): void
    {
        $this->redis->set($this->cacheKey, 'not-a-verdict');
        $http = new MockHttpClient([self::jsonResponse([
            'success' => 1,
            'ip' => ['appears' => 0],
            'emailhash' => ['appears' => 0],
        ])]);

        self::assertSame(IpReputationVerdict::Clean, $this->buildService($http)->check(self::IP, self::EMAIL));
        self::assertSame(1, $http->getRequestsCount());
    }
}
