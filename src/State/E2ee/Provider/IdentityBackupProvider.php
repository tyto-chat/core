<?php

declare(strict_types=1);

namespace App\State\E2ee\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\E2ee\IdentityBackupDto;
use App\Service\E2ee\RecoveryBackupServiceInterface;

/**
 * @implements ProviderInterface<IdentityBackupDto>
 */
final readonly class IdentityBackupProvider implements ProviderInterface
{
    public function __construct(private RecoveryBackupServiceInterface $backups)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): IdentityBackupDto
    {
        $backup = $this->backups->getIdentityBackup();

        return new IdentityBackupDto($backup['blob'], $backup['generation']);
    }
}
