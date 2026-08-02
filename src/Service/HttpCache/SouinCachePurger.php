<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use App\Utils\ApiVersions;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SouinCachePurger implements CachePurgerInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $purgeBaseUrl,
        private readonly LoggerInterface $logger,
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
