<?php

declare(strict_types=1);

namespace App\State\Moderation\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ModeratorNote;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModeratorNoteServiceInterface;
use App\Service\User\UserServiceInterface;

/**
 * @implements ProviderInterface<ModeratorNote>
 */
final readonly class ModeratorNotesProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ModeratorNoteServiceInterface $moderatorNoteService,
        private UserServiceInterface $userService,
    ) {
    }

    /** @return list<ModeratorNote> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $userId = $uriVariables['userId'];
        $target = $this->userService->get((int) $userId);

        return $this->moderatorNoteService->getNotes($community, $target);
    }
}
