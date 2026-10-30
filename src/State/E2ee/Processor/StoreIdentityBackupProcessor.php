<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\IdentityBackupInputDto;
use App\Service\E2ee\RecoveryBackupServiceInterface;

/**
 * @implements ProcessorInterface<IdentityBackupInputDto, void>
 */
final readonly class StoreIdentityBackupProcessor implements ProcessorInterface
{
    public function __construct(private RecoveryBackupServiceInterface $backups)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->backups->storeIdentityBackup((string) $data->blob);
    }
}
