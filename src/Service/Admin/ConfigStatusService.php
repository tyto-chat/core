<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Exception\User\UserNotFoundException;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\User\BotUserServiceInterface;

final readonly class ConfigStatusService implements ConfigStatusServiceInterface
{
    public function __construct(
        private SettingsServiceInterface $settings,
        private BotUserServiceInterface $botUserService,
        private string $mercurePublicUrl,
        private string $meiliMasterKey,
    ) {
    }

    public function isSmtpConfigured(): bool
    {
        return $this->settings->isSmtpConfigured();
    }

    public function isDefaultBotConfigured(): bool
    {
        try {
            $this->botUserService->getDefault();

            return true;
        } catch (\RuntimeException|UserNotFoundException) {
            return false;
        }
    }

    public function isMercureConfigured(): bool
    {
        return '' !== $this->mercurePublicUrl && !str_contains($this->mercurePublicUrl, 'example.com');
    }

    public function isMeiliConfigured(): bool
    {
        return '' !== $this->meiliMasterKey;
    }
}
