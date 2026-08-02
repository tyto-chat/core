<?php

declare(strict_types=1);

namespace App\State\Community\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Notification\SetCommunityMuteDto;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Notification\ChannelUserPreferenceServiceInterface;

/**
 * @implements ProcessorInterface<SetCommunityMuteDto, SetCommunityMuteDto>
 */
final readonly class SetCommunityMuteProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelUserPreferenceServiceInterface $preferenceService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SetCommunityMuteDto
    {
        \assert(null !== $data->muted);
        $community = $this->communityService->getByIdentifier((string) $uriVariables['identifier']);
        $this->preferenceService->setCommunityMuted($community, $data->muted);

        return $data;
    }
}
