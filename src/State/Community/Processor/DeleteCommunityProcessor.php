<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Community;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<Community, null>
 */
final readonly class DeleteCommunityProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->communityService->delete($data);

        return null;
    }
}
