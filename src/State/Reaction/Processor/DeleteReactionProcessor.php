<?php

declare(strict_types=1);

namespace App\State\Reaction\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Reaction;
use App\Service\Reaction\ReactionServiceInterface;

/**
 * @implements ProcessorInterface<Reaction, null>
 */
final readonly class DeleteReactionProcessor implements ProcessorInterface
{
    public function __construct(
        private ReactionServiceInterface $reactionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->reactionService->delete($data);

        return null;
    }
}
