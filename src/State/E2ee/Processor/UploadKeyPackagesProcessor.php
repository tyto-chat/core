<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\KeyPackageInputDto;
use App\Dto\E2ee\UploadKeyPackagesDto;
use App\Service\E2ee\E2eeServiceInterface;

/**
 * @implements ProcessorInterface<UploadKeyPackagesDto, void>
 */
final readonly class UploadKeyPackagesProcessor implements ProcessorInterface
{
    public function __construct(private E2eeServiceInterface $e2ee)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->e2ee->storeKeyPackages(
            (string) $uriVariables['deviceId'],
            array_values(array_map(self::toArray(...), $data->packages)),
            null === $data->lastResort ? null : self::toArray($data->lastResort),
            $data->lastResortReplaces,
            (string) $data->signature,
        );
    }

    /** @return array{data: string, expiresAt: int} */
    private static function toArray(KeyPackageInputDto $package): array
    {
        \assert(null !== $package->data && null !== $package->expiresAt);

        return ['data' => $package->data, 'expiresAt' => $package->expiresAt];
    }
}
