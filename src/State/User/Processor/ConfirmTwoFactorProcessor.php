<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\ConfirmTwoFactorDto;
use App\Dto\User\TwoFactorRecoveryCodesDto;
use App\Service\User\TwoFactorServiceInterface;

/**
 * @implements ProcessorInterface<ConfirmTwoFactorDto, TwoFactorRecoveryCodesDto>
 */
final readonly class ConfirmTwoFactorProcessor implements ProcessorInterface
{
    public function __construct(
        private TwoFactorServiceInterface $twoFactorService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TwoFactorRecoveryCodesDto
    {
        \assert($data instanceof ConfirmTwoFactorDto);

        return $this->twoFactorService->confirm($data->code);
    }
}
