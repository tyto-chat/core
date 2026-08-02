<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Community\CreateCommunityDto;
use App\Entity\Community;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<CreateCommunityDto, Community>
 */
final readonly class CreateCommunityProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Community
    {
        return $this->communityService->new($data);
    }
}
