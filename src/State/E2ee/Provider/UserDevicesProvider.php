<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\PeerDeviceDto;
use App\Dto\E2ee\UserDevicesDto;
use App\Service\E2ee\E2eeServiceInterface;

/**
 * @implements ProviderInterface<UserDevicesDto>
 */
final readonly class UserDevicesProvider implements ProviderInterface
{
    public function __construct(private E2eeServiceInterface $e2ee)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserDevicesDto
    {
        ['account' => $account, 'devices' => $devices] = $this->e2ee->listDevices((int) $uriVariables['userId']);

        return new UserDevicesDto(
            $account?->getPublicKey(),
            $account?->getGeneration(),
            array_map(PeerDeviceDto::fromDevice(...), $devices),
        );
    }
}
