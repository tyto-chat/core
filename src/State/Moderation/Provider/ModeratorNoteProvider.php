<?php

declare(strict_types=1);

namespace App\State\Moderation\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\ModeratorNote;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Moderation\ModeratorNoteServiceInterface;

/**
 * @implements ProviderInterface<ModeratorNote>
 */
final readonly class ModeratorNoteProvider implements ProviderInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ModeratorNoteServiceInterface $moderatorNoteService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ModeratorNote
    {
        $community = $this->communityService->getByIdentifier($uriVariables['community']);

        return $this->moderatorNoteService->getNote((int) $uriVariables['id'], $community);
    }
}
