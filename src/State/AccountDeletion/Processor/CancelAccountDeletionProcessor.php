<?php

declare(strict_types=1);

namespace App\State\AccountDeletion\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\Gdpr\AccountDeletionServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class CancelAccountDeletionProcessor implements ProcessorInterface
{
    public function __construct(
        private AccountDeletionServiceInterface $accountDeletionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->accountDeletionService->cancelDeletion();

        return null;
    }
}
