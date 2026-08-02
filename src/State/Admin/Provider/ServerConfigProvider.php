<?php

declare(strict_types=1);

namespace App\State\Admin\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Admin\ServerConfigDto;
use App\Service\Settings\SettingsServiceInterface;

/**
 * @implements ProviderInterface<ServerConfigDto>
 */
final readonly class ServerConfigProvider implements ProviderInterface
{
    public function __construct(
        private SettingsServiceInterface $settings,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ServerConfigDto
    {
        return ServerConfigDto::fromSettings($this->settings);
    }
}
