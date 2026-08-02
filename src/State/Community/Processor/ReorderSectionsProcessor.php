<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ChannelSection\ReorderSectionsDto;
use App\Service\Channel\ChannelSectionServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<ReorderSectionsDto, null>
 */
final readonly class ReorderSectionsProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelSectionServiceInterface $sectionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var ReorderSectionsDto $data */
        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);
        $this->sectionService->reorderSections($community, $data->sections);

        return null;
    }
}
