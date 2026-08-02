<?php

declare(strict_types=1);

namespace App\Service\Voice;

use App\Entity\Channel;
use App\Entity\User;
use App\Exception\Channel\VoiceBackendUnavailableException;

interface VoiceServiceInterface
{
    public function createRoomToken(User $user, Channel $channel): string;

    public function getPublicUrl(): string;

    /**
     * @return string[]
     *
     * @throws VoiceBackendUnavailableException when the backend cannot be reached —
     *                                          callers must NOT treat that as an empty room
     */
    public function listParticipantIdentities(string $roomName): array;

    public function removeParticipant(string $roomName, string $identity): void;

    /**
     * @return string[]
     *
     * @throws VoiceBackendUnavailableException
     */
    public function listActiveRooms(): array;
}
