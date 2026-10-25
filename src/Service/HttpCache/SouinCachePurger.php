<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use App\Async\RepurgeHttpCacheTagMessage;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use App\Utils\ApiVersions;
use App\Utils\CacheTags;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SouinCachePurger implements CachePurgerInterface
{
    private const int MAX_TOKEN_LIFETIME_SECONDS = 3600;
    public const int REPURGE_DELAY_MS = 5000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $purgeBaseUrl,
        private readonly LoggerInterface $logger,
        private readonly ?HttpCacheSwitchInterface $httpCacheSwitch = null,
        private readonly ?SettingsServiceInterface $settings = null,
        private readonly ?MessageBusInterface $messageBus = null,
        private readonly ?HttpCacheQuarantineInterface $quarantine = null,
    ) {
    }

    #[\Override]
    public function purgeChannelPage(string $communityIdentifier, string $channelIdentifier, int $pageNumber): void
    {
        $path = sprintf('/api/communities/%s/channels/%s/pages/%d', $communityIdentifier, $channelIdentifier, $pageNumber);
        $this->purgeSurrogateKeys($path, $path);
    }

    #[\Override]
    public function purgeChannelExtras(string $communityIdentifier, string $channelIdentifier): void
    {
        $current = sprintf('/api/communities/%s/channels/%s/messages/current', $communityIdentifier, $channelIdentifier);
        $pinned = sprintf('/api/communities/%s/channels/%s/pinned-messages', $communityIdentifier, $channelIdentifier);
        // Souin's documented space-separated multi-key PURGE returns 204 but deletes nothing on pinned souin 1.7.8 + storages/redis 0.0.19 — two single-key requests required.
        $this->purgeSurrogateKeys($current, $current);
        $this->purgeSurrogateKeys($pinned, $pinned);
    }

    #[\Override]
    public function purgeCommunityEmojis(string $communityIdentifier): void
    {
        $path = sprintf('/api/communities/%s/emojis', $communityIdentifier);
        $this->purgeSurrogateKeys($path, $path);
    }

    #[\Override]
    public function purgeMessageThread(string $messageUuid): void
    {
        $path = sprintf('/api/messages/%s/thread', $messageUuid);
        $this->purgeSurrogateKeys($path, $path);
    }

    #[\Override]
    public function purgeMessage(string $messageUuid): void
    {
        $path = sprintf('/api/messages/%s', $messageUuid);
        $this->purgeSurrogateKeys($path, $path);
    }

    #[\Override]
    public function purgeCommunityDetail(string $communityIdentifier): void
    {
        $path = sprintf('/api/communities/%s', $communityIdentifier);
        $this->purgeSurrogateKeys($path, $path);
    }

    #[\Override]
    public function purgeUserContext(int $userId): void
    {
        $this->quarantineOrTrip(fn (HttpCacheQuarantineInterface $q) => $q->quarantineUser($userId), CacheTags::userContext($userId));
        $this->purgeTag(CacheTags::userContext($userId));
    }

    #[\Override]
    public function purgeCommunityContext(int $communityId): void
    {
        $this->purgeTag(CacheTags::communityContext($communityId));
    }

    #[\Override]
    public function purgeCommunityPages(int $communityId): void
    {
        $this->quarantineOrTrip(fn (HttpCacheQuarantineInterface $q) => $q->quarantineCommunity($communityId), CacheTags::communityPages($communityId));
        $this->purgeTag(CacheTags::communityPages($communityId));
    }

    #[\Override]
    public function repurgeTag(string $tag): void
    {
        $this->purgeTag($tag, false);
    }

    #[\Override]
    public function purgeAuthor(int $userId): void
    {
        $this->purgeTag(CacheTags::author($userId));
    }

    private function purgeTag(string $tag, bool $scheduleRepurge = true): void
    {
        if ('' === $this->purgeBaseUrl) {
            return;
        }

        if ($scheduleRepurge && !str_starts_with($tag, 'author-')) {
            $this->scheduleRepurge($tag);
        }

        $error = null;
        foreach ([0.5, 2.0] as $timeout) {
            try {
                $status = $this->httpClient->request('PURGE', rtrim($this->purgeBaseUrl, '/').'/souin-api/souin', [
                    'headers' => ['Surrogate-Key' => $tag],
                    'timeout' => $timeout,
                ])->getStatusCode();
                if ($status < 400) {
                    return;
                }
                $error = 'status '.$status;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $this->logger->error('http_cache.purge_failed', [
            'channel' => 'http_cache',
            'path' => $tag,
            'error' => $error,
        ]);

        if (!str_starts_with($tag, 'author-')) {
            $this->tripSwitch();
        }
    }

    /** @param callable(HttpCacheQuarantineInterface): void $quarantine */
    private function quarantineOrTrip(callable $quarantine, string $tag): void
    {
        if (null === $this->quarantine) {
            return;
        }

        try {
            $quarantine($this->quarantine);
        } catch (\Throwable $e) {
            $this->logger->error('http_cache.quarantine_failed', [
                'channel' => 'http_cache',
                'path' => $tag,
                'error' => $e->getMessage(),
            ]);
            $this->tripSwitch();
        }
    }

    private function tripSwitch(): void
    {
        $contextTtl = $this->settings?->get(Settings::httpCacheContextTtlSeconds()) ?? 0;
        $this->httpCacheSwitch?->trip(max(self::MAX_TOKEN_LIFETIME_SECONDS, $contextTtl));
    }

    private function scheduleRepurge(string $tag): void
    {
        if (null === $this->messageBus) {
            return;
        }

        try {
            $this->messageBus->dispatch(new RepurgeHttpCacheTagMessage($tag), [new DelayStamp(self::REPURGE_DELAY_MS)]);
        } catch (\Throwable $e) {
            $this->logger->error('http_cache.repurge_schedule_failed', [
                'channel' => 'http_cache',
                'path' => $tag,
                'error' => $e->getMessage(),
            ]);
            $this->tripSwitch();
        }
    }

    private function purgeSurrogateKeys(string $surrogateKeyHeader, string $logPath): void
    {
        if ('' === $this->purgeBaseUrl) {
            return;
        }

        // Souin tags entries with the versioned request path — an unversioned Surrogate-Key never matches a stored /api/v1/... entry.
        foreach ($this->expandVersions($surrogateKeyHeader) as $versionedKey) {
            try {
                // The /souin-api/souin/<regexp> purge form is a silent no-op on the pinned Souin — only Surrogate-Key purges work.
                // The discarded response completes synchronously in its destructor — keep the timeout tight or purges block the mutating request.
                $this->httpClient->request('PURGE', rtrim($this->purgeBaseUrl, '/').'/souin-api/souin', [
                    'headers' => ['Surrogate-Key' => $versionedKey],
                    'timeout' => 0.5,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('http_cache.purge_failed', [
                    'channel' => 'http_cache',
                    'path' => $logPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<string> $versions
     *
     * @return list<string> the path re-emitted once per supported API version
     */
    private function expandVersions(string $path, array $versions = ApiVersions::VERSIONS): array
    {
        $bare = preg_replace('#^/api/v\d+#', '/api', $path) ?? $path;
        $suffix = substr($bare, \strlen('/api'));

        return array_map(
            static fn (string $v): string => '/api/'.$v.$suffix,
            $versions,
        );
    }
}
