<?php

declare(strict_types=1);

namespace App\State\User\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\User\TwoFactorStatusDto;
use App\Service\User\TwoFactorServiceInterface;

/**
 * @implements ProviderInterface<TwoFactorStatusDto>
 */
final readonly class TwoFactorStatusProvider implements ProviderInterface
{
    public function __construct(
        private TwoFactorServiceInterface $twoFactorService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        return $this->twoFactorService->status();
    }
}
