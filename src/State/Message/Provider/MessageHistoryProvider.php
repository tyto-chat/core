<?php

declare(strict_types=1);

namespace App\State\Message\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\ArrayPaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\MessageRevision;
use App\Service\Message\MessageServiceInterface;

/** @implements ProviderInterface<MessageRevision> */
final readonly class MessageHistoryProvider implements ProviderInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArrayPaginator
    {
        $message = $this->messageService->getForHistory($uriVariables['id']);
        $revisions = $message->getRevisions()->toArray();

        return new ArrayPaginator($revisions, 0, count($revisions));
    }
}
