<?php

declare(strict_types=1);

namespace App\State\E2ee\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\E2ee\GenerationDto;
use App\Dto\E2ee\ResetAccountDto;
use App\Service\E2ee\RecoveryBackupServiceInterface;

/**
 * @implements ProcessorInterface<ResetAccountDto, GenerationDto>
 */
final readonly class ResetAccountProcessor implements ProcessorInterface
{
    public function __construct(private RecoveryBackupServiceInterface $backups)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GenerationDto
    {
        return new GenerationDto($this->backups->reset((string) $data->newPublicKey, (string) $data->identityBackup, (int) $data->generation));
    }
}
