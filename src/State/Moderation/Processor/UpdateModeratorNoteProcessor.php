<?php

declare(strict_types=1);

namespace App\State\Moderation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Moderation\UpdateModeratorNoteDto;
use App\Entity\ModeratorNote;
use App\Service\Moderation\ModeratorNoteServiceInterface;

/**
 * @implements ProcessorInterface<UpdateModeratorNoteDto, ModeratorNote>
 */
final readonly class UpdateModeratorNoteProcessor implements ProcessorInterface
{
    public function __construct(
        private ModeratorNoteServiceInterface $moderatorNoteService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ModeratorNote
    {
        /** @var UpdateModeratorNoteDto $data */
        /** @var ModeratorNote $note */
        $note = $context['read_data']; // previous_data is a clone (not EM-managed); read_data is the original

        return $this->moderatorNoteService->update($note, $data->content);
    }
}
