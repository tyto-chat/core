<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;

final class SignedUrlService implements SignedUrlServiceInterface
{
    private const string EXPORT_PURPOSE = 'export:';

    private const int MIN_SIGNING_KEY_LENGTH = 32;

    public function __construct(
        private readonly string $signingKey,
        private readonly SettingsServiceInterface $settings,
    ) {
        if (strlen($this->signingKey) < self::MIN_SIGNING_KEY_LENGTH) {
            throw new \InvalidArgumentException(sprintf('APP_MEDIA_SIGNING_KEY must be set to a value of at least %d characters; HMAC media URLs are forgeable otherwise.', self::MIN_SIGNING_KEY_LENGTH));
        }
    }

    public function sign(string $relativePath, ?int $ttl = null): string
    {
        $expiry = time() + ($ttl ?? $this->settings->get(Settings::mediaTokenTtlSeconds()));
        $hmac = hash_hmac('sha256', $relativePath.':'.$expiry, $this->signingKey);

        return $hmac.'.'.$expiry;
    }

    public function signStable(string $relativePath, int $ttl): string
    {
        // +2 buckets — with +1 a token minted at bucket end could expire inside a payload cached for $ttl.
        $expiry = (intdiv(time(), $ttl) + 2) * $ttl;
        $hmac = hash_hmac('sha256', $relativePath.':'.$expiry, $this->signingKey);

        return $hmac.'.'.$expiry;
    }

    public function verify(string $relativePath, string $token): bool
    {
        $parts = explode('.', $token, 2);
        if (2 !== count($parts)) {
            return false;
        }

        [$givenHmac, $expiryStr] = $parts;
        if (1 !== preg_match('/^[1-9]\d{0,18}$/', $expiryStr)) {
            return false;
        }
        $expiry = (int) $expiryStr;

        if ($expiry <= time()) {
            return false;
        }

        $expectedHmac = hash_hmac('sha256', $relativePath.':'.$expiry, $this->signingKey);

        return hash_equals($expectedHmac, $givenHmac);
    }

    #[\Override]
    public function signExport(string $relativePath, int $ttl): string
    {
        return $this->sign(self::EXPORT_PURPOSE.$relativePath, $ttl);
    }

    #[\Override]
    public function verifyExport(string $relativePath, string $token): bool
    {
        return $this->verify(self::EXPORT_PURPOSE.$relativePath, $token);
    }
}
