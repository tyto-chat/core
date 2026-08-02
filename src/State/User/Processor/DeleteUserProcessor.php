<?php

declare(strict_types=1);

namespace App\State\User\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\User;
use App\Service\Gdpr\AccountDeletionServiceInterface;

/**
 * @implements ProcessorInterface<User, void>
 */
final readonly class DeleteUserProcessor implements ProcessorInterface
{
    public function __construct(private AccountDeletionServiceInterface $accountDeletionService)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->accountDeletionService->purgeForUser($data);
    }
}
