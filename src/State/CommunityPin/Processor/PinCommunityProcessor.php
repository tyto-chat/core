<?php

declare(strict_types=1);

namespace App\State\CommunityPin\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CommunityPin\PinCommunityDto;
use App\Entity\CommunityPin;
use App\Service\Community\CommunityPinServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<PinCommunityDto, CommunityPin>
 */
final readonly class PinCommunityProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityPinServiceInterface $pinService,
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommunityPin
    {
        /** @var PinCommunityDto $data */
        $community = $this->communityService->get($data->communityId);

        return $this->pinService->pinForCurrentUser($community);
    }
}
