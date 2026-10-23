<?php

declare(strict_types=1);

namespace App\Service\Realtime;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;

final readonly class MercureSubscriberTokenFactory
{
    public function __construct(
        private string $subscriberJwtKey,
        private SettingsServiceInterface $settings,
    ) {
    }

    public function ttlSeconds(): int
    {
        return $this->settings->get(Settings::realtimeTokenTtlMinutes()) * 60;
    }

    /** @param string[] $topics e.g. ['c/12', 'm/3'] */
    public function create(array $topics, int $ttlSeconds): string
    {
        return new LcobucciFactory($this->subscriberJwtKey, 'hmac.sha256', $ttlSeconds)
            ->create(subscribe: $topics, publish: []);
    }
}
