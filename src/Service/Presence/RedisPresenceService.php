<?php

declare(strict_types=1);

namespace App\Service\Presence;

use App\Dto\Presence\PresenceSnapshot;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Presence\ManualStatus;
use App\Enum\Presence\PresenceState;
use App\Security\SecurityContext;
use App\Security\Voter\CommunityVoter;
use App\Service\Channel\ChannelAudioServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use Predis\ClientInterface;
use Symfony\Contracts\Service\Attribute\Required;

final class RedisPresenceService implements PresenceServiceInterface
{
    private const LIVE_TTL = 180;
    private const REFRESH_THRESHOLD = 150;

    private ChannelAudioServiceInterface $channelAudioService;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly ClientInterface $redis,
        private readonly RealtimePublisherInterface $realtime,
    ) {
    }

    #[Required]
    public function setChannelAudioService(ChannelAudioServiceInterface $service): void
    {
        $this->channelAudioService = $service;
    }

    public function getCommunityOnlineCount(Community $community): int
    {
        $this->security->throwAccessDeniedUnlessGranted(CommunityVoter::VIEW, $community, 'You do not have access to this community.');

        $memberIds = $this->communityMembership->findMemberUserIds($community);
        if ([] === $memberIds) {
            return 0;
        }

        $count = 0;
        foreach ($this->getBatch($memberIds) as $snapshot) {
            if (PresenceState::Offline !== $snapshot->state) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return list<PresenceSnapshot> */
    public function getCommunityOnline(Community $community): array
    {
        $this->security->throwAccessDeniedUnlessGranted(CommunityVoter::VIEW, $community, 'You do not have access to this community.');

        $memberIds = $this->communityMembership->findMemberUserIds($community);
        if ([] === $memberIds) {
            return [];
        }

        $online = [];
        foreach ($this->getBatch($memberIds) as $snapshot) {
            if (PresenceState::Offline !== $snapshot->state) {
                $online[] = $snapshot;
            }
        }

        return $online;
    }

    public function touch(User $user): void
    {
        $userId = $user->getId();
        if (null === $userId) {
            return;
        }

        $liveKey = self::liveKey($userId);
        $manualKey = self::manualKey($userId);

        $ttl = (int) $this->redis->ttl($liveKey);
        if ($ttl > self::REFRESH_THRESHOLD) {
            return;
        }

        $manualRaw = $this->redis->get($manualKey);
        $manual = self::parseManual(\is_string($manualRaw) ? $manualRaw : null);
        $inVoice = $this->isInVoice($userId);

        $prior = self::compute($ttl > 0, $manual, $inVoice);
        $this->redis->setex($liveKey, self::LIVE_TTL, '1');
        $next = self::compute(true, $manual, $inVoice);

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

        $liveKey = self::liveKey($userId);
        $manualKey = self::manualKey($userId);

        $ttl = (int) $this->redis->ttl($liveKey);
        $manualRaw = $this->redis->get($manualKey);
        $priorManual = self::parseManual(\is_string($manualRaw) ? $manualRaw : null);
        $inVoice = $this->isInVoice($userId);

        $prior = self::compute($ttl > 0, $priorManual, $inVoice);

        if (null === $status) {
            $this->redis->del([$manualKey]);
        } else {
            $this->redis->set($manualKey, $status->value);
        }

        $next = self::compute($ttl > 0, $status, $inVoice);

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

        $liveKey = self::liveKey($userId);
        $manualKey = self::manualKey($userId);

        $ttl = (int) $this->redis->ttl($liveKey);
        $manualRaw = $this->redis->get($manualKey);
        $manual = self::parseManual(\is_string($manualRaw) ? $manualRaw : null);
        $inVoice = $this->isInVoice($userId);

        $prior = self::compute($ttl > 0, $manual, $inVoice);
        $this->redis->del([$liveKey]);
        $next = self::compute(false, $manual, $inVoice);

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

        $this->realtime->publishPresenceChanged($userId, $this->resolveState($userId));
    }

    public function get(User $user): PresenceSnapshot
    {
        $userId = $user->getId();
        if (null === $userId) {
            return new PresenceSnapshot(0, PresenceState::Offline);
        }

        return $this->getBatch([$userId])[$userId];
    }

    public function getBatch(array $userIds): array
    {
        $userIds = array_values($userIds);

        $result = [];
        foreach ($userIds as $id) {
            $result[$id] = new PresenceSnapshot($id, PresenceState::Offline);
        }

        if ([] === $userIds) {
            return $result;
        }

        $keys = [];
        foreach ($userIds as $id) {
            $keys[] = self::liveKey($id);
            $keys[] = self::manualKey($id);
        }

        /** @var list<?string> $values */
        $values = $this->redis->mget($keys);
        $voiceIds = array_flip($this->channelAudioService->filterActiveVoiceUserIds($userIds));

        foreach ($userIds as $i => $id) {
            $live = null !== ($values[$i * 2] ?? null);
            $manual = self::parseManual($values[$i * 2 + 1] ?? null);
            $inVoice = isset($voiceIds[$id]);
            $result[$id] = new PresenceSnapshot($id, self::compute($live, $manual, $inVoice));
        }

        return $result;
    }

    private function resolveState(int $userId): PresenceState
    {
        $ttl = (int) $this->redis->ttl(self::liveKey($userId));
        $manualRaw = $this->redis->get(self::manualKey($userId));
        $manual = self::parseManual(\is_string($manualRaw) ? $manualRaw : null);
        $inVoice = $this->isInVoice($userId);

        return self::compute($ttl > 0, $manual, $inVoice);
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

    private static function parseManual(?string $raw): ?ManualStatus
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        return ManualStatus::tryFrom($raw);
    }

    private static function liveKey(int $userId): string
    {
        return 'presence:user:'.$userId.':live';
    }

    private static function manualKey(int $userId): string
    {
        return 'presence:user:'.$userId.':manual';
    }
}
