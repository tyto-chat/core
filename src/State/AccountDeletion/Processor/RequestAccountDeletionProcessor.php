<?php

declare(strict_types=1);

namespace App\State\AccountDeletion\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\AccountDeletion;
use App\Service\Gdpr\AccountDeletionServiceInterface;

/**
 * @implements ProcessorInterface<mixed, AccountDeletion>
 */
final readonly class RequestAccountDeletionProcessor implements ProcessorInterface
{
    public function __construct(
        private AccountDeletionServiceInterface $accountDeletionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AccountDeletion
    {
        $user = $this->accountDeletionService->requestDeletion();

        $status = new AccountDeletion();
        $status->purgeAt = $user->getDeletionRequestedAt()?->modify('+'.AccountDeletionServiceInterface::GRACE_PERIOD_DAYS.' days');
        $status->pending = true;

        return $status;
    }
}
