<?php

declare(strict_types=1);

namespace App\State\Channel\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Notification\SetChannelLevelDto;
use App\Entity\Channel;
use App\Service\Notification\ChannelUserPreferenceServiceInterface;

/**
 * @implements ProcessorInterface<SetChannelLevelDto, SetChannelLevelDto>
 */
final readonly class SetChannelNotificationLevelProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelUserPreferenceServiceInterface $preferenceService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SetChannelLevelDto
    {
        /** @var SetChannelLevelDto $data */
        /** @var Channel $channel */
        $channel = $context['read_data'];
        $this->preferenceService->setChannelLevel($channel, $data->level);

        return $data;
    }
}
