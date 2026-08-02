<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\CurrentPasswordDto;
use App\Dto\User\TwoFactorRecoveryCodesDto;
use App\Service\User\TwoFactorServiceInterface;

/**
 * @implements ProcessorInterface<CurrentPasswordDto, TwoFactorRecoveryCodesDto>
 */
final readonly class RegenerateRecoveryCodesProcessor implements ProcessorInterface
{
    public function __construct(
        private TwoFactorServiceInterface $twoFactorService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TwoFactorRecoveryCodesDto
    {
        \assert($data instanceof CurrentPasswordDto);

        return $this->twoFactorService->regenerateRecoveryCodes($data->currentPassword);
    }
}
