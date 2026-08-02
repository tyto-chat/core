<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Channel;
use App\Service\Channel\ChannelReadStateServiceInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class MarkChannelReadProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelReadStateServiceInterface $channelReadStateService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var Channel $channel */
        $channel = $context['read_data'];
        $this->channelReadStateService->markRead($channel);

        return null;
    }
}
