<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Dto\Presence\PresenceSnapshot;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Presence\ManualStatus;
use App\Enum\Presence\PresenceState;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Presence\PresenceServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Test double for the presence backend. Holds liveness + manual override in
 * arrays — no Redis required. Mirrors RedisPresenceService transition logic
 * so test assertions on Mercure publishes stay realistic.
 */
final class InMemoryPresenceService implements PresenceServiceInterface
{
    /** @var array<int, true> */
    private array $live = [];

    /** @var array<int, ManualStatus> */
    private array $manual = [];

    private ChannelAudioServiceInterface $channelAudioService;

    public function __construct(
        private readonly RealtimePublisherInterface $realtime,
        private readonly CommunityMembershipServiceInterface $communityMembership,
    ) {
    }

    public function getCommunityOnlineCount(Community $community): int
    {
        $count = 0;
        foreach ($this->getBatch($this->communityMembership->findMemberUserIds($community)) as $snapshot) {
            if (PresenceState::Offline !== $snapshot->state) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return list<PresenceSnapshot> */
    public function getCommunityOnline(Community $community): array
    {
        $online = [];
        foreach ($this->getBatch($this->communityMembership->findMemberUserIds($community)) as $snapshot) {
            if (PresenceState::Offline !== $snapshot->state) {
                $online[] = $snapshot;
            }
        }

        return $online;
    }

    #[Required]
    public function setChannelAudioService(ChannelAudioServiceInterface $service): void
    {
        $this->channelAudioService = $service;
    }

    public function touch(User $user): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $inVoice = $this->isInVoice($userId);
        $prior = self::compute(isset($this->live[$userId]), $this->manual[$userId] ?? null, $inVoice);
        $this->live[$userId] = true;
        $next = self::compute(true, $this->manual[$userId] ?? null, $inVoice);

        if ($prior !== $next) {
            $this->realtime->publishPresenceChanged($userId, $next);
        }
    }

    public function setManualStatus(User $user, ?ManualStatus $status): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $live = isset($this->live[$userId]);
        $inVoice = $this->isInVoice($userId);
        $prior = self::compute($live, $this->manual[$userId] ?? null, $inVoice);

        if (null === $status) {
            unset($this->manual[$userId]);
        } else {
            $this->manual[$userId] = $status;
        }

        $next = self::compute($live, $this->manual[$userId] ?? null, $inVoice);

        if ($prior !== $next) {
            $this->realtime->publishPresenceChanged($userId, $next);
        }
    }

    public function offline(User $user): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $live = isset($this->live[$userId]);
        $inVoice = $this->isInVoice($userId);
        $prior = self::compute($live, $this->manual[$userId] ?? null, $inVoice);
        unset($this->live[$userId]);
        $next = self::compute(false, $this->manual[$userId] ?? null, $inVoice);

        if ($prior !== $next) {
            $this->realtime->publishPresenceChanged($userId, $next);
        }
    }

    public function reevaluate(User $user): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $state = self::compute(
            isset($this->live[$userId]),
            $this->manual[$userId] ?? null,
            $this->isInVoice($userId),
        );
        $this->realtime->publishPresenceChanged($userId, $state);
    }

    public function get(User $user): PresenceSnapshot
    {
        $userId = $user->getId();
        if (null === $userId) {
            return new PresenceSnapshot(0, PresenceState::Offline);
        }

        return new PresenceSnapshot(
            $userId,
            self::compute(
                isset($this->live[$userId]),
                $this->manual[$userId] ?? null,
                $this->isInVoice($userId),
            ),
        );
    }

    public function getBatch(array $userIds): array
    {
        $voiceIds = array_flip($this->channelAudioService->filterActiveVoiceUserIds($userIds));
        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = new PresenceSnapshot(
                $id,
                self::compute(isset($this->live[$id]), $this->manual[$id] ?? null, isset($voiceIds[$id])),
            );
        }

        return $out;
    }

    public function reset(): void
    {
        $this->live = [];
        $this->manual = [];
    }

    private function isInVoice(int $userId): bool
    {
        return [] !== $this->channelAudioService->filterActiveVoiceUserIds([$userId]);
    }

    private static function compute(bool $live, ?ManualStatus $manual, bool $inVoice): PresenceState
    {
        if (ManualStatus::Invisible === $manual) {
            return PresenceState::Offline;
        }

        if (null !== $manual) {
            return $manual->toState();
        }

        if ($live || $inVoice) {
            return PresenceState::Online;
        }

        return PresenceState::Offline;
    }
}
