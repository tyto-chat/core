<?php

declare(strict_types=1);

namespace App\State\CommunityPin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Service\Community\CommunityPinServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class UnpinCommunityProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityPinServiceInterface $pinService,
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $community = $this->communityService->get((int) $uriVariables['communityId']);
        $this->pinService->unpin($community);

        return null;
    }
}
