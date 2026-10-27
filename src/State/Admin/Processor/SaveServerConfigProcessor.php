<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\ServerConfigDto;
use App\Dto\Admin\ServerConfigPatchDto;
use App\Enum\Settings\EncryptedDmsMode;
use App\Exception\E2ee\EncryptedDmsModeNotAvailableException;
use App\Service\HttpCache\HttpCacheSwitchInterface;
use App\Service\Settings\SettingsServiceInterface;

/**
 * @implements ProcessorInterface<ServerConfigPatchDto, ServerConfigDto>
 */
final readonly class SaveServerConfigProcessor implements ProcessorInterface
{
    public function __construct(
        private SettingsServiceInterface $settings,
        private HttpCacheSwitchInterface $httpCacheSwitch,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ServerConfigDto
    {
        if (EncryptedDmsMode::Required === ($data->encryptedDmsMode ?? null)) {
            throw new EncryptedDmsModeNotAvailableException();
        }
        $this->settings->applyPatch($data);
        $this->httpCacheSwitch->sync();

        return ServerConfigDto::fromSettings($this->settings);
    }
}
