<?php

declare(strict_types=1);

namespace App\Service\Voice;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Entity\Channel;
use App\Entity\User;
use App\Exception\Channel\VoiceBackendUnavailableException;
use App\Utils\VoiceIdentity;
use Psr\Log\LoggerInterface;

class LiveKitVoiceService implements VoiceServiceInterface
{
    private ?RoomServiceClient $roomService = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly string $publicUrl,
        private readonly string $internalUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function createRoomToken(User $user, Channel $channel): string
    {
        $identity = VoiceIdentity::user($user);
        $roomName = VoiceIdentity::room($channel);
        $profileName = $user->getProfile()?->getName() ?? $identity;

        $grant = (new VideoGrant())
            ->setRoomJoin()
            ->setRoomName($roomName)
            ->setCanPublish()
            ->setCanSubscribe()
            ->setCanPublishSources(['microphone', 'camera', 'screen_share', 'screen_share_audio']);

        $options = (new AccessTokenOptions())
            ->setIdentity($identity)
            ->setName($profileName)
            ->setTtl(3600);

        return (new AccessToken($this->apiKey, $this->apiSecret))
            ->init($options)
            ->setGrant($grant)
            ->toJwt();
    }

    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    public function listParticipantIdentities(string $roomName): array
    {
        try {
            $identities = [];
            foreach ($this->roomService()->listParticipants($roomName)->getParticipants() as $participant) {
                $identities[] = $participant->getIdentity();
            }

            return $identities;
        } catch (\Throwable $e) {
            $this->logger->error('voice.list_participants_failed', ['room' => $roomName, 'error' => $e->getMessage()]);

            throw new VoiceBackendUnavailableException(sprintf('Could not list participants of room "%s".', $roomName), 0, $e);
        }
    }

    public function removeParticipant(string $roomName, string $identity): void
    {
        try {
            $this->roomService()->removeParticipant($roomName, $identity);
        } catch (\Throwable $e) {
            // Best-effort: the periodic reconcile removes stale participants.
            $this->logger->warning('voice.remove_participant_failed', ['room' => $roomName, 'identity' => $identity, 'error' => $e->getMessage()]);
        }
    }

    public function listActiveRooms(): array
    {
        try {
            $names = [];
            foreach ($this->roomService()->listRooms()->getRooms() as $room) {
                $names[] = $room->getName();
            }

            return $names;
        } catch (\Throwable $e) {
            $this->logger->error('voice.list_rooms_failed', ['error' => $e->getMessage()]);

            throw new VoiceBackendUnavailableException('Could not list active voice rooms.', 0, $e);
        }
    }

    private function roomService(): RoomServiceClient
    {
        return $this->roomService ??= new RoomServiceClient($this->internalUrl, $this->apiKey, $this->apiSecret);
    }
}
