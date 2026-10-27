<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\ClaimedKeyPackageDto;
use App\Dto\E2ee\ItemsDto;
use App\Service\E2ee\E2eeServiceInterface;

/**
 * @implements ProcessorInterface<mixed, ItemsDto>
 */
final readonly class ClaimKeyPackagesProcessor implements ProcessorInterface
{
    public function __construct(private E2eeServiceInterface $e2ee)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ItemsDto
    {
        return new ItemsDto(array_map(
            static fn (array $row): ClaimedKeyPackageDto => new ClaimedKeyPackageDto($row['deviceId'], $row['keyPackage']),
            $this->e2ee->claimKeyPackages((int) $uriVariables['userId']),
        ));
    }
}
