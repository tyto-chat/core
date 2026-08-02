<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\CurrentPasswordDto;
use App\Service\User\TwoFactorServiceInterface;

/**
 * @implements ProcessorInterface<CurrentPasswordDto, void>
 */
final readonly class DisableTwoFactorProcessor implements ProcessorInterface
{
    public function __construct(
        private TwoFactorServiceInterface $twoFactorService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof CurrentPasswordDto);

        $this->twoFactorService->disable($data->currentPassword);
    }
}
