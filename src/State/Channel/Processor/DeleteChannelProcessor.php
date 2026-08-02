<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Channel;
use App\Service\Channel\ChannelServiceInterface;

/**
 * @implements ProcessorInterface<Channel, void>
 */
final readonly class DeleteChannelProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelServiceInterface $channelService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $this->channelService->delete($data);
    }
}
