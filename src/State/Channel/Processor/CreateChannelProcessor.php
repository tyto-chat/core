<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Channel\CreateChannelDto;
use App\Entity\Channel;
use App\Service\Channel\ChannelServiceInterface;

/**
 * @implements ProcessorInterface<CreateChannelDto, Channel>
 */
final readonly class CreateChannelProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelServiceInterface $channelService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Channel
    {
        return $this->channelService->new($data);
    }
}
