<?php

declare(strict_types=1);

namespace App\State\Voice\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Voice\VoiceCallTokenDto;
use App\Entity\Channel;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Exception\Channel\NotAnAudioChannelException;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Voice\VoiceServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, VoiceCallTokenDto>
 */
final readonly class CreateVoiceTokenProcessor implements ProcessorInterface
{
    public function __construct(
        private VoiceServiceInterface $voiceService,
        private ChannelAudioServiceInterface $channelAudioService,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VoiceCallTokenDto
    {
        /** @var Channel $channel */
        $channel = $context['read_data'];
        if (ChannelType::Audio !== $channel->getType()) {
            throw new NotAnAudioChannelException('Channel is not an audio channel.');
        }

        $this->channelAudioService->joinAudioChannelAsCurrentUser($channel);

        /** @var User $user */
        $user = $this->security->getUser();

        return new VoiceCallTokenDto(
            token: $this->voiceService->createRoomToken($user, $channel),
            url: $this->voiceService->getPublicUrl(),
        );
    }
}
