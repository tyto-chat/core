<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\HistoryHeadsDto;
use App\Service\E2ee\RecoveryBackupServiceInterface;

/**
 * @implements ProviderInterface<HistoryHeadsDto>
 */
final readonly class HistoryHeadsProvider implements ProviderInterface
{
    public function __construct(private RecoveryBackupServiceInterface $backups)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HistoryHeadsDto
    {
        $heads = $this->backups->historyHeads();

        return new HistoryHeadsDto([] === $heads ? new \stdClass() : $heads);
    }
}
