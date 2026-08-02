<?php

declare(strict_types=1);

namespace App\State\Admin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Admin\ServerConfigDto;
use App\Dto\Admin\ServerConfigPatchDto;
use App\Service\Settings\SettingsServiceInterface;

/**
 * @implements ProcessorInterface<ServerConfigPatchDto, ServerConfigDto>
 */
final readonly class SaveServerConfigProcessor implements ProcessorInterface
{
    public function __construct(
        private SettingsServiceInterface $settings,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ServerConfigDto
    {
        $this->settings->applyPatch($data);

        return ServerConfigDto::fromSettings($this->settings);
    }
}
