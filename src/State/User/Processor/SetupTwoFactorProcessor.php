<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\User\TwoFactorSetupDto;
use App\Service\User\TwoFactorServiceInterface;

/**
 * @implements ProcessorInterface<mixed, TwoFactorSetupDto>
 */
final readonly class SetupTwoFactorProcessor implements ProcessorInterface
{
    public function __construct(
        private TwoFactorServiceInterface $twoFactorService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TwoFactorSetupDto
    {
        return $this->twoFactorService->setup();
    }
}
