<?php

declare(strict_types=1);

namespace App\Service\IpReputation;

final class IpReputationAllowlist
{
    public function matches(string $rawList, string $ip, string $email): bool
    {
        $email = strtolower(trim($email));
        $emailDomain = str_contains($email, '@') ? substr($email, (int) strrpos($email, '@')) : '';

        $lines = preg_split('/\R/', $rawList);
        foreach (false === $lines ? [] : $lines as $line) {
            $entry = strtolower(trim($line));
            if ('' === $entry || str_starts_with($entry, '#')) {
                continue;
            }
            if (str_starts_with($entry, '@')) {
                if ($entry === $emailDomain) {
                    return true;
                }
                continue;
            }
            if (str_contains($entry, '@')) {
                if ($entry === $email) {
                    return true;
                }
                continue;
            }
            if (str_contains($entry, '/')) {
                if ($this->cidrContains($entry, $ip)) {
                    return true;
                }
                continue;
            }
            $entryBin = @inet_pton($entry);
            $ipBin = @inet_pton($ip);
            if (false !== $entryBin && false !== $ipBin && $entryBin === $ipBin) {
                return true;
            }
            if (false === $entryBin && false === $ipBin && $entry === strtolower($ip)) {
                return true;
            }
        }

        return false;
    }

    private function cidrContains(string $cidr, string $ip): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2) + [1 => ''];
        if ('' === $bits || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if (false === $ipBin || false === $subnetBin || \strlen($ipBin) !== \strlen($subnetBin)) {
            return false;
        }
        $maxBits = 8 * \strlen($ipBin);
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        if (0 !== $fullBytes && 0 !== substr_compare($ipBin, $subnetBin, 0, $fullBytes)) {
            return false;
        }
        if (0 === $remainder) {
            return true;
        }
        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (\ord($ipBin[$fullBytes]) & $mask) === (\ord($subnetBin[$fullBytes]) & $mask);
    }
}
