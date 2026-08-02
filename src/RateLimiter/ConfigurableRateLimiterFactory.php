<?php

declare(strict_types=1);

namespace App\RateLimiter;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

final class ConfigurableRateLimiterFactory implements RateLimiterFactoryInterface
{
    public const string SCOPE_LOGIN = 'login';
    public const string SCOPE_SESSION_REFRESH = 'session_refresh';
    public const string SCOPE_REGISTER = 'register';
    public const string SCOPE_API_WRITE = 'api_write';
    public const string SCOPE_ATTACHMENT_UPLOAD = 'attachment_upload';
    public const string SCOPE_MESSAGE_SEND = 'message_send';
    public const string SCOPE_PASSWORD_RESET = 'password_reset';
    public const string SCOPE_SEARCH = 'search';
    public const string SCOPE_REPORT = 'report';
    public const string SCOPE_TWO_FACTOR = 'two_factor';

    public function __construct(
        private readonly string $scope,
        private readonly SettingsServiceInterface $settings,
        private readonly StorageInterface $storage,
    ) {
    }

    public function create(?string $key = null): LimiterInterface
    {
        // Inner factory must be rebuilt per call — Symfony freezes limits at construction, caching it would pin stale settings.
        [$limit, $intervalSeconds] = match ($this->scope) {
            self::SCOPE_LOGIN => [$this->settings->get(Settings::rateLoginLimit()), $this->settings->get(Settings::rateLoginIntervalSeconds())],
            self::SCOPE_SESSION_REFRESH => [$this->settings->get(Settings::rateSessionRefreshLimit()), $this->settings->get(Settings::rateSessionRefreshIntervalSeconds())],
            self::SCOPE_REGISTER => [$this->settings->get(Settings::rateRegisterLimit()), $this->settings->get(Settings::rateRegisterIntervalSeconds())],
            self::SCOPE_API_WRITE => [$this->settings->get(Settings::rateApiWriteLimit()), $this->settings->get(Settings::rateApiWriteIntervalSeconds())],
            self::SCOPE_ATTACHMENT_UPLOAD => [$this->settings->get(Settings::rateAttachmentUploadLimit()), $this->settings->get(Settings::rateAttachmentUploadIntervalSeconds())],
            self::SCOPE_MESSAGE_SEND => [$this->settings->get(Settings::rateMessageSendLimit()), $this->settings->get(Settings::rateMessageSendIntervalSeconds())],
            self::SCOPE_PASSWORD_RESET => [$this->settings->get(Settings::ratePasswordResetLimit()), $this->settings->get(Settings::ratePasswordResetIntervalSeconds())],
            self::SCOPE_SEARCH => [$this->settings->get(Settings::rateSearchLimit()), $this->settings->get(Settings::rateSearchIntervalSeconds())],
            self::SCOPE_REPORT => [$this->settings->get(Settings::rateReportLimit()), $this->settings->get(Settings::rateReportIntervalSeconds())],
            self::SCOPE_TWO_FACTOR => [$this->settings->get(Settings::rateTwoFactorLimit()), $this->settings->get(Settings::rateTwoFactorIntervalSeconds())],
            default => throw new \InvalidArgumentException(sprintf('Unknown rate-limit scope "%s".', $this->scope)),
        };

        $inner = new RateLimiterFactory(
            [
                'id' => 'rate_'.$this->scope,
                'policy' => 'sliding_window',
                'limit' => $limit,
                'interval' => $intervalSeconds.' seconds',
            ],
            $this->storage,
        );

        return $inner->create($key);
    }
}
