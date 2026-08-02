<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\ReconcileAudioParticipantsMessage;
use App\Service\Channel\ChannelAudioServiceInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ReconcileAudioParticipantsHandler
{
    public function __construct(
        private readonly ChannelAudioServiceInterface $channelAudioService,
    ) {
    }

    public function __invoke(ReconcileAudioParticipantsMessage $message): void
    {
        $this->channelAudioService->reconcileActiveRooms();
    }
}
