<?php

declare(strict_types=1);

namespace App\State\ChannelSection\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\ChannelSection;
use App\Service\Channel\ChannelSectionServiceInterface;

/** @implements ProcessorInterface<ChannelSection, null> */
final readonly class RemoveChannelSectionProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelSectionServiceInterface $channelSectionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->channelSectionService->delete($data);

        return null;
    }
}
