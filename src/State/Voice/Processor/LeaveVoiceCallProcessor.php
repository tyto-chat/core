<?php

declare(strict_types=1);

namespace App\State\Voice\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Channel;
use App\Entity\User;
use App\Service\Channel\ChannelAudioServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class LeaveVoiceCallProcessor implements ProcessorInterface
{
    public function __construct(
        private ChannelAudioServiceInterface $channelAudioService,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        /** @var Channel $channel */
        $channel = $context['read_data'];

        /** @var User $user */
        $user = $this->security->getUser();
        $this->channelAudioService->leaveAudioChannel($user, $channel);

        return null;
    }
}
