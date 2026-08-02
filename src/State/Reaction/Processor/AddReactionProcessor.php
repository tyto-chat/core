<?php

declare(strict_types=1);

namespace App\State\Reaction\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Reaction\AddReactionDto;
use App\Entity\Reaction;
use App\Service\Message\MessageServiceInterface;
use App\Service\Reaction\ReactionServiceInterface;

/**
 * @implements ProcessorInterface<AddReactionDto, Reaction>
 */
final readonly class AddReactionProcessor implements ProcessorInterface
{
    public function __construct(
        private ReactionServiceInterface $reactionService,
        private MessageServiceInterface $messageService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reaction
    {
        /** @var AddReactionDto $data */
        $message = $this->messageService->getById($uriVariables['messageId']);

        return $this->reactionService->new($message, $data->emoji);
    }
}
