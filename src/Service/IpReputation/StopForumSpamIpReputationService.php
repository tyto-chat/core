<?php

declare(strict_types=1);

namespace App\Service\IpReputation;

use App\Enum\IpReputation\IpReputationVerdict;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class StopForumSpamIpReputationService implements IpReputationServiceInterface
{
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly SettingsServiceInterface $settings,
        private readonly HttpClientInterface $httpClient,
        private readonly ClientInterface $redis,
        private readonly IpReputationAllowlist $allowlist,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[\Override]
    public function check(string $ip, string $email, ?string $username = null): IpReputationVerdict
    {
        if (!$this->settings->get(Settings::ipReputationEnabled())) {
            return IpReputationVerdict::Clean;
        }

        if ($this->allowlist->matches($this->settings->get(Settings::ipReputationAllowlist()), $ip, $email)) {
            return IpReputationVerdict::Clean;
        }

        $emailHash = md5(strtolower(trim($email)));
        $username = null !== $username ? trim($username) : null;
        $checkUsername = $this->settings->get(Settings::ipReputationCheckUsername()) && null !== $username && '' !== $username;
        $cacheKey = 'ip_reputation:'.sha1($ip.'|'.$emailHash.'|'.($checkUsername ? strtolower($username) : ''));

        $cached = $this->cacheGet($cacheKey);
        if (null !== $cached) {
            return $cached;
        }

        $verdict = $this->query($ip, $emailHash, $checkUsername ? $username : null);

        if (IpReputationVerdict::Unavailable !== $verdict) {
            $this->cacheSet($cacheKey, $verdict);
        }

        return $verdict;
    }

    private function query(string $ip, string $emailHash, ?string $username): IpReputationVerdict
    {
        $body = ['ip' => $ip, 'emailhash' => $emailHash, 'json' => ''];
        if (null !== $username) {
            $body['username'] = $username;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->settings->get(Settings::ipReputationEndpoint()), '/').'/api', [
                'body' => $body,
                'timeout' => 3,
            ]);
            /** @var array<string, mixed> $data */
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger?->warning('ip_reputation.check_failed', ['error' => $e->getMessage()]);

            return IpReputationVerdict::Unavailable;
        }

        if (1 !== ($data['success'] ?? 0)) {
            $this->logger?->warning('ip_reputation.check_failed', ['error' => 'success != 1']);

            return IpReputationVerdict::Unavailable;
        }

        $min = (float) $this->settings->get(Settings::ipReputationConfidenceMin());
        foreach (['ip', 'emailhash', 'username'] as $field) {
            $entry = $data[$field] ?? null;
            if (\is_array($entry) && 1 === ($entry['appears'] ?? 0) && (float) ($entry['confidence'] ?? 0) >= $min) {
                return IpReputationVerdict::Flagged;
            }
        }

        return IpReputationVerdict::Clean;
    }

    private function cacheGet(string $key): ?IpReputationVerdict
    {
        try {
            $raw = $this->redis->get($key);
        } catch (\Throwable $e) {
            $this->logger?->warning('ip_reputation.cache_failed', ['error' => $e->getMessage()]);

            return null;
        }

        return \is_string($raw) ? IpReputationVerdict::tryFrom($raw) : null;
    }

    private function cacheSet(string $key, IpReputationVerdict $verdict): void
    {
        try {
            $this->redis->setex($key, self::CACHE_TTL, $verdict->value);
        } catch (\Throwable $e) {
            $this->logger?->warning('ip_reputation.cache_failed', ['error' => $e->getMessage()]);
        }
    }
}
