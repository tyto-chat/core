<?php

declare(strict_types=1);

namespace App\State\Stats\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Stats;
use App\Service\ServerInfo\SystemInfoServiceInterface;

/**
 * @implements ProviderInterface<Stats>
 */
final readonly class StatsProvider implements ProviderInterface
{
    public function __construct(
        private SystemInfoServiceInterface $systemInfoService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Stats
    {
        $dto = new Stats();
        $dto->system = $this->systemInfoService->getSystemInfo();
        $dto->communities = $this->systemInfoService->getCommunityStats();

        return $dto;
    }
}
