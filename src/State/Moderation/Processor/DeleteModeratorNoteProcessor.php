<?php

declare(strict_types=1);

namespace App\State\Moderation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ModeratorNote;
use App\Service\Moderation\ModeratorNoteServiceInterface;

/**
 * @implements ProcessorInterface<ModeratorNote, null>
 */
final readonly class DeleteModeratorNoteProcessor implements ProcessorInterface
{
    public function __construct(
        private ModeratorNoteServiceInterface $moderatorNoteService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->moderatorNoteService->delete($data);

        return null;
    }
}
