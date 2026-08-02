<?php

declare(strict_types=1);

namespace App\State\CommunityPin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CommunityPin\ReorderPinnedCommunitiesDto;
use App\Service\Community\CommunityPinServiceInterface;

/**
 * @implements ProcessorInterface<ReorderPinnedCommunitiesDto, null>
 */
final readonly class ReorderPinnedCommunitiesProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityPinServiceInterface $pinService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->pinService->reorder($data->communityIds);

        return null;
    }
}
