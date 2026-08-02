<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Channel\SetChannelPinStateDto;
use App\Entity\Channel;
use App\Service\Notification\ChannelUserPreferenceServiceInterface;

/**
 * @implements ProcessorInterface<SetChannelPinStateDto, SetChannelPinStateDto>
 */
final readonly class SetChannelPinStateProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelUserPreferenceServiceInterface $preferenceService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SetChannelPinStateDto
    {
        /** @var SetChannelPinStateDto $data */
        /** @var Channel $channel */
        $channel = $context['read_data'];
        $this->preferenceService->setPinState($channel, $data->pinState);

        return $data;
    }
}
