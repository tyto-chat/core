<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Channel\ReorderChannelsDto;
use App\Exception\ChannelSection\ChannelSectionNotFoundException;
use App\Service\Channel\ChannelSectionServiceInterface;

/**
 * @implements ProcessorInterface<ReorderChannelsDto, null>
 */
final readonly class ReorderChannelsProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelSectionServiceInterface $sectionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var ReorderChannelsDto $data */
        $section = $this->sectionService->get((int) $uriVariables['id']);
        if ($section->getCommunity()?->getIdentifier() !== (string) $uriVariables['community']) {
            throw new ChannelSectionNotFoundException(sprintf('Channel section %d not found in community "%s".', (int) $uriVariables['id'], (string) $uriVariables['community']));
        }
        $this->sectionService->reorderChannels($section, $data->channels);

        return null;
    }
}
