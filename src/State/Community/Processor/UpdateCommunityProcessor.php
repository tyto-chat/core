<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Community\UpdateCommunityDto;
use App\Entity\Community;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<UpdateCommunityDto, Community>
 */
final readonly class UpdateCommunityProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Community
    {
        /** @var UpdateCommunityDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['identifier']);

        return $this->communityService->update($community, $data);
    }
}
