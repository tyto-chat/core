<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\HttpCache;

use App\Service\HttpCache\SouinCachePurger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[AllowMockObjectsWithoutExpectations]
class SouinCachePurgerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    #[\Override]
    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testEmptyBaseUrlIsNoOp(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('http client must not be called when purge base url is empty');
        });

        $purger = new SouinCachePurger($httpClient, '', $this->logger);
        $purger->purgeChannelPage('comm', 'chan', 7);

        $this->addToAssertionCount(1);
    }

    public function testSendsPurgeRequestWithSurrogateKeyHeader(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeChannelPage('comm', 'chan', 7);

        self::assertSame('PURGE', $capturedMethod);
        self::assertSame('http://app:8888/souin-api/souin', $capturedUrl);
        self::assertContains('Surrogate-Key: /api/v1/communities/comm/channels/chan/pages/7', $capturedOptions['headers'] ?? []);
    }

    public function testTrailingSlashOnBaseUrlIsNotDuplicated(): void
    {
        $capturedUrl = null;

        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse('', ['http_code' => 200]);
        });

        $purger = new SouinCachePurger($httpClient, 'http://app:8888/', $this->logger);
        $purger->purgeChannelPage('comm', 'chan', 7);

        self::assertSame('http://app:8888/souin-api/souin', $capturedUrl);
    }

    public function testTransportExceptionIsLoggedNotThrown(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });

        $this->logger->expects(self::once())->method('warning')->with(
            'http_cache.purge_failed',
            self::callback(static function (array $context): bool {
                return 'http_cache' === $context['channel']
                    && '/api/communities/comm/channels/chan/pages/7' === $context['path']
                    && str_contains($context['error'], 'connection refused');
            }),
        );

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeChannelPage('comm', 'chan', 7);
    }

    public function testNon2xxResponseIsLoggedNotThrown(): void
    {
        // The discarded response's destructor completes the request inside the
        // try block and throws ServerException on 5xx — same as CurlHttpClient.
        $httpClient = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        $this->logger->expects(self::once())->method('warning')->with(
            'http_cache.purge_failed',
            self::callback(static function (array $context): bool {
                return 'http_cache' === $context['channel']
                    && '/api/communities/comm/channels/chan/pages/7' === $context['path']
                    && str_contains($context['error'], '500');
            }),
        );

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeChannelPage('comm', 'chan', 7);
    }

    public function testChannelExtrasIsNoOpOnEmptyBaseUrl(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('http client must not be called when purge base url is empty');
        });

        $purger = new SouinCachePurger($httpClient, '', $this->logger);
        $purger->purgeChannelExtras('comm', 'chan');

        $this->addToAssertionCount(1);
    }

    public function testChannelExtrasSendsTwoSequentialSingleKeyRequests(): void
    {
        // A single PURGE with a space-separated Surrogate-Key list was tried
        // first and found to be a no-op against this project's pinned Souin
        // (Task 5 image-level smoke run: 204 response, neither key deleted).
        // Two sequential single-key requests are the only form empirically
        // confirmed to work.
        $capturedMethods = [];
        $capturedUrls = [];
        $capturedOptionsList = [];

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethods, &$capturedUrls, &$capturedOptionsList): MockResponse {
            $capturedMethods[] = $method;
            $capturedUrls[] = $url;
            $capturedOptionsList[] = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeChannelExtras('comm', 'chan');

        self::assertSame(['PURGE', 'PURGE'], $capturedMethods);
        self::assertSame(['http://app:8888/souin-api/souin', 'http://app:8888/souin-api/souin'], $capturedUrls);
        self::assertContains(
            'Surrogate-Key: /api/v1/communities/comm/channels/chan/messages/current',
            $capturedOptionsList[0]['headers'] ?? [],
        );
        self::assertContains(
            'Surrogate-Key: /api/v1/communities/comm/channels/chan/pinned-messages',
            $capturedOptionsList[1]['headers'] ?? [],
        );
    }

    public function testChannelExtrasTransportExceptionIsLoggedNotThrownForEitherKey(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });

        $this->logger->expects(self::exactly(2))->method('warning')->with(
            'http_cache.purge_failed',
            self::callback(static function (array $context): bool {
                return 'http_cache' === $context['channel']
                    && str_contains($context['error'], 'connection refused');
            }),
        );

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeChannelExtras('comm', 'chan');
    }

    public function testCommunityEmojisIsNoOpOnEmptyBaseUrl(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('http client must not be called when purge base url is empty');
        });

        $purger = new SouinCachePurger($httpClient, '', $this->logger);
        $purger->purgeCommunityEmojis('comm');

        $this->addToAssertionCount(1);
    }

    public function testCommunityEmojisSendsPurgeRequestTargetingEmojisPath(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeCommunityEmojis('comm');

        self::assertSame('PURGE', $capturedMethod);
        self::assertSame('http://app:8888/souin-api/souin', $capturedUrl);
        self::assertContains('Surrogate-Key: /api/v1/communities/comm/emojis', $capturedOptions['headers'] ?? []);
    }

    public function testMessageThreadIsNoOpOnEmptyBaseUrl(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('http client must not be called when purge base url is empty');
        });

        $purger = new SouinCachePurger($httpClient, '', $this->logger);
        $purger->purgeMessageThread('11111111-1111-1111-1111-111111111111');

        $this->addToAssertionCount(1);
    }

    public function testMessageThreadSendsPurgeRequestTargetingThreadPath(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeMessageThread('11111111-1111-1111-1111-111111111111');

        self::assertSame('PURGE', $capturedMethod);
        self::assertSame('http://app:8888/souin-api/souin', $capturedUrl);
        self::assertContains(
            'Surrogate-Key: /api/v1/messages/11111111-1111-1111-1111-111111111111/thread',
            $capturedOptions['headers'] ?? [],
        );
    }

    public function testMessageThreadTransportExceptionIsLoggedNotThrown(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });

        $this->logger->expects(self::once())->method('warning')->with(
            'http_cache.purge_failed',
            self::callback(static function (array $context): bool {
                return 'http_cache' === $context['channel']
                    && '/api/messages/11111111-1111-1111-1111-111111111111/thread' === $context['path']
                    && str_contains($context['error'], 'connection refused');
            }),
        );

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeMessageThread('11111111-1111-1111-1111-111111111111');
    }

    public function testMessageIsNoOpOnEmptyBaseUrl(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('http client must not be called when purge base url is empty');
        });

        $purger = new SouinCachePurger($httpClient, '', $this->logger);
        $purger->purgeMessage('abc-uuid');

        $this->addToAssertionCount(1);
    }

    public function testMessageSendsPurgeRequestTargetingItemPath(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeMessage('abc-uuid');

        self::assertSame('PURGE', $capturedMethod);
        self::assertSame('http://app:8888/souin-api/souin', $capturedUrl);
        self::assertContains('Surrogate-Key: /api/v1/messages/abc-uuid', $capturedOptions['headers'] ?? []);
    }

    public function testMessageTransportExceptionIsLoggedNotThrown(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });

        $this->logger->expects(self::once())->method('warning')->with(
            'http_cache.purge_failed',
            self::callback(static function (array $context): bool {
                return 'http_cache' === $context['channel']
                    && '/api/messages/abc-uuid' === $context['path']
                    && str_contains($context['error'], 'connection refused');
            }),
        );

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeMessage('abc-uuid');
    }

    public function testCommunityDetailIsNoOpOnEmptyBaseUrl(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \LogicException('http client must not be called when purge base url is empty');
        });

        $purger = new SouinCachePurger($httpClient, '', $this->logger);
        $purger->purgeCommunityDetail('comm');

        $this->addToAssertionCount(1);
    }

    public function testCommunityDetailSendsPurgeRequestTargetingCommunityPath(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedOptions = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedOptions): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedOptions = $options;

            return new MockResponse('', ['http_code' => 200]);
        });

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeCommunityDetail('comm');

        self::assertSame('PURGE', $capturedMethod);
        self::assertSame('http://app:8888/souin-api/souin', $capturedUrl);
        self::assertContains('Surrogate-Key: /api/v1/communities/comm', $capturedOptions['headers'] ?? []);
    }

    public function testCommunityDetailTransportExceptionIsLoggedNotThrown(): void
    {
        $httpClient = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });

        $this->logger->expects(self::once())->method('warning')->with(
            'http_cache.purge_failed',
            self::callback(static function (array $context): bool {
                return 'http_cache' === $context['channel']
                    && '/api/communities/comm' === $context['path']
                    && str_contains($context['error'], 'connection refused');
            }),
        );

        $purger = new SouinCachePurger($httpClient, 'http://app:8888', $this->logger);
        $purger->purgeCommunityDetail('comm');
    }

    public function testExpandVersionsMapsUnversionedPathAcrossEverySupportedVersion(): void
    {
        // Pins the key-expansion helper's contract directly, independent of
        // how many versions App\Utils\ApiVersions::VERSIONS currently lists —
        // with two supported versions an unversioned /api/... path must fan
        // out to one Surrogate-Key per version, so purging never leaves a
        // second version's cached entry invisible (task-4, spec §2 dormant
        // requirement — currently VERSIONS = ['v1'] so every call site only
        // ever emits one key today).
        $purger = new SouinCachePurger(new MockHttpClient(), '', $this->logger);
        $method = new \ReflectionMethod(SouinCachePurger::class, 'expandVersions');

        self::assertSame(
            ['/api/v1/messages/x', '/api/v2/messages/x'],
            $method->invoke($purger, '/api/messages/x', ['v1', 'v2']),
        );
    }

    public function testExpandVersionsStripsAnyExistingVersionSegmentBeforeReExpanding(): void
    {
        // Purge callers may pass either a bare path or one already carrying a
        // version segment (e.g. from an IRI) — expandVersions must normalize
        // either shape to the same expansion, not double up the prefix.
        $purger = new SouinCachePurger(new MockHttpClient(), '', $this->logger);
        $method = new \ReflectionMethod(SouinCachePurger::class, 'expandVersions');

        self::assertSame(
            ['/api/v1/messages/x', '/api/v2/messages/x'],
            $method->invoke($purger, '/api/v1/messages/x', ['v1', 'v2']),
        );
    }
}
