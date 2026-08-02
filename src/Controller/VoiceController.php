<?php

declare(strict_types=1);

namespace App\Controller;

use Agence104\LiveKit\WebhookReceiver;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Utils\VoiceIdentity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VoiceController extends AbstractController
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly UserServiceInterface $userService,
        private readonly ChannelServiceInterface $channelService,
        private readonly ChannelAudioServiceInterface $channelAudioService,
    ) {
    }

    #[Route('/api/livekit/webhook', name: 'api_voice_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        try {
            $event = (new WebhookReceiver($this->apiKey, $this->apiSecret))->receive(
                $request->getContent(),
                $request->headers->get('Authorization'),
            );
        } catch (\Throwable) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $channelId = VoiceIdentity::channelIdFrom($event->getRoom()?->getName() ?? '');
        $channel = null !== $channelId ? $this->channelService->find($channelId) : null;
        if (!$channel) {
            return $this->json(['ok' => true]);
        }

        if ('room_finished' === $event->getEvent()) {
            $this->channelAudioService->handleRoomFinished($channel);

            return $this->json(['ok' => true]);
        }

        $userId = VoiceIdentity::userIdFrom($event->getParticipant()?->getIdentity() ?? '');
        $user = null !== $userId ? $this->userService->find($userId) : null;
        if (!$user) {
            return $this->json(['ok' => true]);
        }

        match ($event->getEvent()) {
            'participant_joined' => $this->channelAudioService->joinAudioChannel($user, $channel),
            'participant_left' => $this->channelAudioService->leaveAudioChannel($user, $channel),
            default => null,
        };

        return $this->json(['ok' => true]);
    }
}
