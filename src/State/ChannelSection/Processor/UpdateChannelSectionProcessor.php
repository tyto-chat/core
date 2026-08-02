<?php

declare(strict_types=1);

namespace App\State\ChannelSection\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\ChannelSection\UpdateChannelSectionDto;
use App\Entity\ChannelSection;
use App\Service\Channel\ChannelSectionServiceInterface;

/**
 * @implements ProcessorInterface<UpdateChannelSectionDto, ChannelSection>
 */
final readonly class UpdateChannelSectionProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelSectionServiceInterface $channelSectionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ChannelSection
    {
        /** @var UpdateChannelSectionDto $data */
        /** @var ChannelSection $section */
        $section = $context['read_data'];

        return $this->channelSectionService->update($section, $data);
    }
}
