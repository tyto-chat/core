<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Channel\UpdateChannelDto;
use App\Entity\Channel;
use App\Service\Channel\ChannelServiceInterface;

/**
 * @implements ProcessorInterface<UpdateChannelDto, Channel>
 */
final readonly class UpdateChannelProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelServiceInterface $channelService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Channel
    {
        /** @var UpdateChannelDto $data */
        /** @var Channel $channel */
        $channel = $context['read_data'];

        return $this->channelService->update($channel, $data);
    }
}
