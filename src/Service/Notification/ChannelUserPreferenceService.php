<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Channel;
use App\Entity\ChannelUserPreference;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Channel\ChannelPinState;
use App\Exception\Community\NotAMemberException;
use App\Repository\ChannelUserPreferenceRepository;
use App\Security\SecurityContext;
use App\Security\Voter\ChannelVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\User\UserServiceInterface;

class ChannelUserPreferenceService extends AbstractDoctrineService implements ChannelUserPreferenceServiceInterface
{
    public const string CATEGORY_PLAIN = 'plain';
    public const string CATEGORY_MENTION = 'mention';

    public function __construct(
        private readonly SecurityContext $security,
        private readonly ChannelUserPreferenceRepository $preferenceRepository,
        private readonly CommunityMembershipServiceInterface $membershipService,
        private readonly UserServiceInterface $userService,
    ) {
    }

    #[\Override]
    public function getChannelLevel(User $user, Channel $channel): ChannelNotificationLevel
    {
        return $this->preferenceRepository->findForUser($channel, $user)?->getLevel()
            ?? ChannelNotificationLevel::Mentions;
    }

    #[\Override]
    public function isCommunityMuted(User $user, Community $community): bool
    {
        $member = $this->membershipService->findOneByUserAndCommunity($user, $community);

        return null !== $member && $member->isNotificationsMuted();
    }

    #[\Override]
    public function setChannelLevel(Channel $channel, ?ChannelNotificationLevel $level): void
    {
        $this->upsert($channel, static function (ChannelUserPreference $row) use ($level): void {
            $row->setLevel($level ?? ChannelNotificationLevel::Mentions);
        });
    }

    #[\Override]
    public function setPinState(Channel $channel, ?ChannelPinState $state): void
    {
        $this->upsert($channel, static function (ChannelUserPreference $row) use ($state): void {
            $row->setPinState($state);
        });
    }

    /** @param callable(ChannelUserPreference): void $mutate */
    private function upsert(Channel $channel, callable $mutate): void
    {
        $caller = $this->security->currentUser('You must be signed in to change channel preferences.');

        $this->security->throwAccessDeniedUnlessGranted(
            ChannelVoter::VIEW,
            $channel,
            'You do not have access to this channel.',
        );

        $row = $this->preferenceRepository->findForUser($channel, $caller)
            ?? (new ChannelUserPreference())->setUser($caller)->setChannel($channel);
        $mutate($row);

        if ($row->isDefault()) {
            if (null !== $row->getId()) {
                $this->removeAndFlush($row);
            }

            return;
        }

        $this->save($row);
    }

    #[\Override]
    public function setCommunityMuted(Community $community, bool $muted): void
    {
        $caller = $this->security->currentUser('You must be signed in to change notification preferences.');

        $member = $this->membershipService->findOneByUserAndCommunity($caller, $community);
        if (null === $member) {
            throw new NotAMemberException();
        }

        $member->setNotificationsMuted($muted);
        $this->save($member);
    }

    #[\Override]
    public function getAllForCurrentUser(): array
    {
        $caller = $this->security->currentUser('You must be signed in to view notification preferences.');

        $mutedCommunityIds = [];
        foreach ($this->membershipService->findMutedMemberships($caller) as $member) {
            $id = $member->getCommunity()->getId();
            if (null !== $id) {
                $mutedCommunityIds[] = $id;
            }
        }

        return [
            'channels' => $this->preferenceRepository->findAllForUser($caller),
            'mutedCommunityIds' => $mutedCommunityIds,
        ];
    }

    #[\Override]
    public function filterRecipients(Channel $channel, array $candidateUserIds, string $category): array
    {
        if ([] === $candidateUserIds) {
            return [];
        }

        $community = $channel->getCommunity();

        $users = $this->userService->findByIds(array_values(array_unique($candidateUserIds)));

        $allowed = [];
        foreach ($users as $user) {
            // Drops non-members (incl. admins who view without joining) — they must not be notified or mentionable.
            if (null !== $community && !$this->membershipService->isMember($user, $community)) {
                continue;
            }
            if (null !== $community && $this->isCommunityMuted($user, $community)) {
                continue;
            }
            $level = $this->getChannelLevel($user, $channel);
            if (ChannelNotificationLevel::None === $level) {
                continue;
            }
            if (self::CATEGORY_PLAIN === $category && ChannelNotificationLevel::All !== $level) {
                continue;
            }
            $uid = $user->getId();
            if (null !== $uid) {
                $allowed[] = $uid;
            }
        }

        return $allowed;
    }
}
