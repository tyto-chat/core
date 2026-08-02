<?php

declare(strict_types=1);

namespace App\State\AccountDeletion\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AccountDeletion;
use App\Service\Gdpr\AccountDeletionServiceInterface;

/**
 * @implements ProviderInterface<AccountDeletion>
 */
final readonly class AccountDeletionStatusProvider implements ProviderInterface
{
    public function __construct(
        private AccountDeletionServiceInterface $accountDeletionService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AccountDeletion
    {
        $status = new AccountDeletion();
        $status->purgeAt = $this->accountDeletionService->findPurgeDateForCurrentUser();
        $status->pending = null !== $status->purgeAt;

        return $status;
    }
}
