<?php

declare(strict_types=1);

namespace App\State\Message\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Message\SendMessageDto;
use App\Entity\Message;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Message\MessageServiceInterface;

/**
 * @implements ProcessorInterface<SendMessageDto, Message>
 */
final readonly class SendChannelMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private CommunityServiceInterface $communityService,
        private ChannelServiceInterface $channelService,
        private MessageServiceInterface $messageService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Message
    {
        /** @var SendMessageDto $data */
        $community = $this->communityService->getByIdentifier($uriVariables['community']);
        $channel = $this->channelService->getByIdentifier($uriVariables['channel'], $community);

        return $this->messageService->sendToChannel($channel, $data->text, array_values($data->attachmentIris));
    }
}
