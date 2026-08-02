<?php

declare(strict_types=1);

namespace App\State\Moderation\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Moderation\CreateModeratorNoteDto;
use App\Entity\ModeratorNote;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModeratorNoteServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProcessorInterface<CreateModeratorNoteDto, ModeratorNote>
 */
final readonly class CreateModeratorNoteProcessor implements ProcessorInterface
{
    public function __construct(
        private ModeratorNoteServiceInterface $moderatorNoteService,
        private CommunityServiceInterface $communityService,
        private UserServiceInterface $userService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ModeratorNote
    {
        /** @var CreateModeratorNoteDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $userId = $uriVariables['userId'];
        $target = $this->userService->get((int) $userId);

        return $this->moderatorNoteService->new($community, $target, $data->content);
    }
}
