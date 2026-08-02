<?php

declare(strict_types=1);

namespace App\State\ChannelSection\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ChannelSection\CreateChannelSectionDto;
use App\Entity\ChannelSection;
use App\Service\Channel\ChannelSectionServiceInterface;
use App\Service\Community\CommunityServiceInterface;

/**
 * @implements ProcessorInterface<CreateChannelSectionDto, ChannelSection>
 */
final readonly class CreateChannelSectionProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelSectionServiceInterface $channelSectionService,
        private CommunityServiceInterface $communityService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ChannelSection
    {
        $community = $this->communityService->getByIdentifier((string) $uriVariables['community']);

        return $this->channelSectionService->new($community, $data);
    }
}
