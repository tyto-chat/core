<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\RevokeDeviceDto;
use App\Service\E2ee\E2eeServiceInterface;

/**
 * @implements ProcessorInterface<RevokeDeviceDto, void>
 */
final readonly class RevokeDeviceProcessor implements ProcessorInterface
{
    public function __construct(private E2eeServiceInterface $e2ee)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $revocation = $data->revocation;
        \assert(null !== $revocation && null !== $revocation->signerDeviceId && null !== $revocation->revokedAt && null !== $revocation->signature);

        $this->e2ee->revokeDevice((string) $uriVariables['deviceId'], $revocation->signerDeviceId, $revocation->revokedAt, $revocation->signature);
    }
}
