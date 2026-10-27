<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\ItemsDto;
use App\Dto\E2ee\OwnDeviceDto;
use App\Service\E2ee\E2eeServiceInterface;

/**
 * @implements ProviderInterface<ItemsDto>
 */
final readonly class OwnDevicesProvider implements ProviderInterface
{
    public function __construct(private E2eeServiceInterface $e2ee)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ItemsDto
    {
        return new ItemsDto(array_map(OwnDeviceDto::fromDevice(...), $this->e2ee->listOwnDevices()));
    }
}
