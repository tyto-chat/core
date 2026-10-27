<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\OwnDeviceDto;
use App\Dto\E2ee\RegisterDeviceDto;
use App\Service\E2ee\E2eeServiceInterface;

/**
 * @implements ProcessorInterface<RegisterDeviceDto, OwnDeviceDto>
 */
final readonly class RegisterDeviceProcessor implements ProcessorInterface
{
    public function __construct(private E2eeServiceInterface $e2ee)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OwnDeviceDto
    {
        \assert(null !== $data->deviceId && null !== $data->name && null !== $data->publicKey && null !== $data->server && null !== $data->credential);

        return OwnDeviceDto::fromDevice($this->e2ee->registerDevice($data->deviceId, $data->name, $data->publicKey, $data->server, $data->credential));
    }
}
