<?php

declare(strict_types=1);

namespace App\State\Message\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Message;
use App\Service\Message\MessageServiceInterface;

/**
 * @implements ProcessorInterface<mixed, Message>
 */
final readonly class UnpinMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageServiceInterface $messageService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Message
    {
        $message = $this->messageService->getById((string) $uriVariables['id']);

        return $this->messageService->unpin($message);
    }
}
