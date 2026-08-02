<?php

declare(strict_types=1);

namespace App\Service\Webhook;

final class WebhookUrlGuard
{
    /**
     * @return string[] validated public IPs, or [] meaning "blocked / allow-internal"
     */
    public function resolvedIps(string $host, bool $allowInternal): array
    {
        if ($allowInternal) {
            return [];
        }

        // Strip IPv6 brackets: http://[::1]/ → ::1
        $bare = ltrim(rtrim($host, ']'), '[');
        if (false !== filter_var($bare, FILTER_VALIDATE_IP)) {
            $ips = [$bare];
        } else {
            $ips = $this->dnsLookup($host);
        }

        if ([] === $ips) {
            return [];
        }

        foreach ($ips as $ip) {
            // Known gap: PHP's flags miss some IPv6 ranges (ULA fc00::/7 partially, NAT64 64:ff9b::, 6to4).
            if (false === filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            )) {
                return [];
            }
        }

        return $ips;
    }

    /**
     * @param string $url           absolute URL to check
     * @param bool   $allowInternal when true, private/loopback addresses are allowed
     */
    public function isAllowed(string $url, bool $allowInternal): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($host) || !in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if ($allowInternal) {
            return true;
        }

        $ips = $this->resolvedIps($host, false);

        return [] !== $ips;
    }

    /**
     * @return string[]
     */
    private function dnsLookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (false === $records || [] === $records) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $ips[] = $record['ip'];
            } elseif (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return $ips;
    }
}
