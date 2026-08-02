<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Async\DisconnectVoiceParticipantMessage;
use App\Dto\Community\CreateCommunityDto;
use App\Dto\Community\UpdateCommunityDto;
use App\Dto\ServerInfo\CommunityStatsDto;
use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\MediaObject;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Enum\Community\CommunityRole;
use App\Exception\Community\AlreadyMemberException;
use App\Exception\Community\CannotLeaveLastAdminException;
use App\Exception\Community\CommunityNotFoundException;
use App\Exception\Community\InvalidWelcomeChannelException;
use App\Exception\Community\NotAMemberException;
use App\Repository\CommunityRepository;
use App\Security\SecurityContext;
use App\Security\Voter\CommunityVoter;
use App\Service\AbstractDoctrineService;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\Message\WelcomeMessageServiceInterface;
use App\Service\Moderation\ModerationServiceInterface;
use App\Service\Presence\PresenceServiceInterface;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use App\Service\Search\SearchServiceInterface;
use App\Service\User\UserServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Service\Attribute\Required;

class CommunityService extends AbstractDoctrineService implements CommunityServiceInterface
{
    private CommunityPinServiceInterface $communityPinService;
    private ChannelServiceInterface $channelService;
    private PresenceServiceInterface $presenceService;
    private WelcomeMessageServiceInterface $welcomeMessageService;
    private StructureRealtimePublisherInterface $realtimePublisher;
    private MessageBusInterface $messageBus;
    private CachePurgerInterface $cachePurger;

    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private MediaObjectServiceInterface $mediaObjectService,
        private CommunityRepository $communityRepository,
        private UserServiceInterface $userService,
        private ModerationServiceInterface $moderationService,
        private SearchServiceInterface $searchService,
        private ChannelMembershipServiceInterface $channelMembershipService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    #[Required]
    public function setCommunityPinService(CommunityPinServiceInterface $communityPinService): void
    {
        $this->communityPinService = $communityPinService;
    }

    #[Required]
    public function setChannelService(ChannelServiceInterface $channelService): void
    {
        $this->channelService = $channelService;
    }

    #[Required]
    public function setPresenceService(PresenceServiceInterface $presenceService): void
    {
        $this->presenceService = $presenceService;
    }

    #[Required]
    public function setWelcomeMessageService(WelcomeMessageServiceInterface $welcomeMessageService): void
    {
        $this->welcomeMessageService = $welcomeMessageService;
    }

    #[Required]
    public function setRealtimePublisher(StructureRealtimePublisherInterface $realtimePublisher): void
    {
        $this->realtimePublisher = $realtimePublisher;
    }

    #[Required]
    public function setMessageBus(MessageBusInterface $messageBus): void
    {
        $this->messageBus = $messageBus;
    }

    #[Required]
    public function setCachePurger(CachePurgerInterface $cachePurger): void
    {
        $this->cachePurger = $cachePurger;
    }

    #[\Override]
    public function new(CreateCommunityDto $createCommunityDto): Community
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only Admins can create communities.');
        $community = new Community();

        return $this->save($community, $createCommunityDto);
    }

    /**
     * @throws AccessDeniedException
     * @throws CommunityNotFoundException
     */
    #[\Override]
    public function get(int $id): Community
    {
        return $this->getByCriteria(['id' => $id]);
    }

    #[\Override]
    public function findByIdentifier(string $identifier): ?Community
    {
        return $this->communityRepository->findOneBy(['identifier' => $identifier]);
    }

    #[\Override]
    public function findByLogo(MediaObject $logo): ?Community
    {
        return $this->communityRepository->findByLogo($logo);
    }

    #[\Override]
    public function getByIdentifier(string $identifier): Community
    {
        return $this->getByCriteria(['identifier' => $identifier]);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @throws AccessDeniedException
     * @throws CommunityNotFoundException
     */
    private function getByCriteria(array $criteria): Community
    {
        $community = $this->communityRepository->findOneBy($criteria);
        if (null === $community) {
            throw new CommunityNotFoundException(sprintf('Community not found using criteria: %s.', json_encode($criteria, \JSON_THROW_ON_ERROR)));
        }

        if (!$this->security->isGranted(CommunityVoter::VIEW, $community)) {
            // Anonymous → 401; authenticated denial → 404 (no existence leak).
            $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view this community.');

            throw new CommunityNotFoundException(sprintf('Community not found using criteria: %s.', json_encode($criteria, \JSON_THROW_ON_ERROR)));
        }

        return $community;
    }

    /**
     * @return Community[]
     */
    #[\Override]
    public function getAll(): array
    {
        $user = $this->security->getUser();
        if (null === $user) {
            return $this->communityRepository->findPublic();
        }
        if ($this->security->isAdmin()) {
            return $this->communityRepository->findAll();
        }

        return $this->communityRepository->findPublicOrJoined($user);
    }

    #[\Override]
    public function getPublic(): array
    {
        return $this->communityRepository->findPublic();
    }

    #[\Override]
    public function getPublicStats(): array
    {
        $memberCounts = $this->communityMembership->countMembersPerCommunity();
        $channelCounts = $this->channelService->countPublicActivePerCommunity();

        $stats = [];
        foreach ($this->getPublic() as $community) {
            $stats[$community->getIdentifier()] = new CommunityStatsDto(
                memberCount: $memberCounts[$community->getId()] ?? 0,
                onlineCount: $this->presenceService->getCommunityOnlineCount($community),
                channelCount: $channelCounts[$community->getId()] ?? 0,
            );
        }

        return $stats;
    }

    #[\Override]
    public function getMembers(Community $community): array
    {
        $this->security->throwAccessDeniedUnlessAuthenticated('You must be signed in to view community members.');
        $this->security->throwAccessDeniedUnlessGranted(CommunityVoter::VIEW, $community, 'You do not have permission to view members of this community.');

        $members = array_filter(
            $this->communityMembership->findByCommunity($community),
            static fn (CommunityMember $m) => !$m->getUser()->isBot(),
        );
        $members = array_values($members);

        $explicitMemberUserIds = array_map(
            static fn (CommunityMember $m) => $m->getUserId(),
            $members
        );

        // Transient CommunityMember rows for global admins, never persisted — a GET must not mutate DB state.
        foreach ($this->userService->getAdmins() as $admin) {
            if (in_array($admin->getId(), $explicitMemberUserIds, true)) {
                continue;
            }

            $adminMember = new CommunityMember();
            $adminMember->setUser($admin);
            $adminMember->setCommunity($community);
            $members[] = $adminMember;
        }

        return $members;
    }

    #[\Override]
    public function getMember(Community $community, int $memberId): CommunityMember
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'You do not have permission to manage community members.');

        $member = $this->communityMembership->findById($memberId, $community);
        if (!$member) {
            throw new NotAMemberException(sprintf('Member %d not found in this community.', $memberId));
        }

        return $member;
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function updateMemberRole(CommunityMember $member, CommunityRole $role): CommunityMember
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($member->getCommunity(), 'You do not have permission to update member roles.');

        if (CommunityRole::Admin === $member->getRole()
            && CommunityRole::Admin !== $role
            && 1 === $this->communityMembership->countByRole($member->getCommunity(), CommunityRole::Admin)
        ) {
            throw new CannotLeaveLastAdminException('The last admin cannot be demoted.');
        }

        $member->setRole($role);

        $saved = $this->save($member);
        $community = $member->getCommunity();
        $this->realtimePublisher->publishCommunityStructureChanged($community);
        $this->realtimePublisher->publishUserEvent(
            (int) $member->getUser()->getId(),
            'role.changed',
            ['communityIdentifier' => (string) $community->getIdentifier()],
        );

        return $saved;
    }

    /**
     * @return array<Community>
     */
    #[\Override]
    public function getJoined(): array
    {
        if ($this->security->isAdmin()) {
            return $this->getAll();
        }

        $user = $this->security->getUser();
        if (null === $user) {
            return [];
        }

        return $this->communityRepository->findJoined($user);
    }

    /**
     * @throws AlreadyMemberException
     * @throws AccessDeniedException
     */
    #[\Override]
    public function join(Community $community): void
    {
        $currentUser = $this->security->currentUser('You need to be signed in to join community.');

        $this->security->throwAccessDeniedIf($community->isPrivate() && !$this->security->isAdmin(), 'This community is invite-only.');
        $this->security->throwAccessDeniedIf($this->moderationService->isBanned($community, $currentUser), 'You are banned from this community.');

        if ($this->communityMembership->isMember($currentUser, $community)) {
            throw new AlreadyMemberException('You are already a member of this community.');
        }

        $communityMember = new CommunityMember();
        $communityMember->setUser($currentUser);
        $communityMember->setCommunity($community);

        $this->save($communityMember);

        $this->communityPinService->pinFor($currentUser, $community);

        $this->welcomeMessageService->sendIfConfigured($community, $currentUser);
        $this->realtimePublisher->publishCommunityStructureChanged($community);
    }

    /**
     * @throws NotAMemberException
     */
    #[\Override]
    public function leave(Community $community): void
    {
        $currentUser = $this->security->currentUser('You need to be signed in to leave community.');

        $communityMember = $this->communityMembership->findOneByUserAndCommunity($currentUser, $community);
        if (null === $communityMember) {
            throw new NotAMemberException('You are not a member of this community.');
        }

        if (CommunityRole::Admin === $communityMember->getRole()
            && 1 === $this->communityMembership->countByRole($community, CommunityRole::Admin)
        ) {
            throw new CannotLeaveLastAdminException('The last admin cannot leave a community.');
        }

        $this->removeAndFlush($communityMember);
        $this->removeAccessResidue($currentUser, $community);
        $this->communityPinService->unpinFor($currentUser, $community);
        $this->realtimePublisher->publishCommunityStructureChanged($community);
        $this->messageBus->dispatch(new DisconnectVoiceParticipantMessage(
            (int) $currentUser->getId(),
            communityIdentifier: $community->getIdentifier(),
        ));
    }

    private function removeAccessResidue(User $user, Community $community): void
    {
        $this->channelMembershipService->removeAllForUserInCommunity($user, $community);
        $this->userGroupService->removeMembershipsForUserInCommunity($user, $community);
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function addMember(Community $community, User $user, CommunityRole $role = CommunityRole::Member, bool $sendWelcome = true): CommunityMember
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'You do not have permission to add members to this community.');

        $this->security->throwAccessDeniedIf($this->moderationService->isBanned($community, $user), 'This user is banned from this community.');

        $existing = $this->communityMembership->findOneByUserAndCommunity($user, $community);
        if (null !== $existing) {
            return $existing;
        }

        $communityMember = new CommunityMember();
        $communityMember->setUser($user);
        $communityMember->setCommunity($community);
        $communityMember->setRole($role);

        $saved = $this->save($communityMember);

        $this->communityPinService->pinFor($user, $community);

        if ($sendWelcome) {
            $this->welcomeMessageService->sendIfConfigured($community, $user);
        }

        $this->realtimePublisher->publishCommunityStructureChanged($community);

        return $saved;
    }

    #[\Override]
    public function joinViaInvite(Community $community): CommunityMember
    {
        $user = $this->security->currentUser('You need to be signed in to accept an invite.');

        $this->security->throwAccessDeniedIf($this->moderationService->isBanned($community, $user), 'You are banned from this community.');

        $existing = $this->communityMembership->findOneByUserAndCommunity($user, $community);
        if (null !== $existing) {
            return $existing;
        }

        $communityMember = new CommunityMember();
        $communityMember->setUser($user);
        $communityMember->setCommunity($community);

        $saved = $this->save($communityMember);

        $this->communityPinService->pinFor($user, $community);

        $this->welcomeMessageService->sendIfConfigured($community, $user);

        return $saved;
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function update(Community $community, UpdateCommunityDto $updateCommunityDto): Community
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'Only admins can update communities.');

        if ($updateCommunityDto->isProvided('welcomeChannelIdentifier')) {
            $identifier = $updateCommunityDto->welcomeChannelIdentifier;
            if (null === $identifier) {
                $community->setWelcomeChannel(null);
            } else {
                try {
                    $channel = $this->channelService->getByIdentifier($identifier, $community);
                } catch (\Throwable) {
                    throw new InvalidWelcomeChannelException();
                }
                if (ChannelType::Text !== $channel->getType() || $channel->isPrivate() || $channel->isArchived()) {
                    throw new InvalidWelcomeChannelException();
                }
                $community->setWelcomeChannel($channel);
            }
        }

        $saved = $this->save($community, $updateCommunityDto);
        $this->realtimePublisher->publishCommunityStructureChanged($saved);

        return $saved;
    }

    /**
     * @throws AccessDeniedException
     */
    #[\Override]
    public function delete(Community $community): void
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only admins can delete communities.');

        $identifier = (string) $community->getIdentifier();
        $channelIds = array_map(
            static fn ($channel): int => (int) $channel->getId(),
            $community->getChannels()->toArray(),
        );
        $this->mediaObjectService->deleteCommunityAttachments($community);
        $this->removeAndFlush($community);
        foreach ($channelIds as $channelId) {
            $this->searchService->removeChannelDocuments($channelId);
        }
        // No structure-publish on delete — without a direct purge the cached detail outlives the entity.
        $this->cachePurger->purgeCommunityDetail($identifier);
    }

    /**
     * @throws NotAMemberException
     */
    #[\Override]
    public function transferAdminRole(Community $community, User $newAdmin, bool $demoteOthers): int
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only server admins can transfer the community admin role.');
        $member = $this->communityMembership->findOneByUserAndCommunity($newAdmin, $community);
        if (null === $member) {
            throw new NotAMemberException('Target user is not a member of this community.');
        }

        $member->setRole(CommunityRole::Admin);

        $demoted = 0;
        if ($demoteOthers) {
            foreach ($this->communityMembership->findByCommunity($community) as $existing) {
                if ($existing->getId() === $member->getId() || CommunityRole::Admin !== $existing->getRole()) {
                    continue;
                }
                $existing->setRole(CommunityRole::Member);
                ++$demoted;
            }
        }

        $this->flush();

        $this->realtimePublisher->publishCommunityStructureChanged($community);
        $this->realtimePublisher->publishUserEvent(
            (int) $newAdmin->getId(),
            'role.changed',
            ['communityIdentifier' => (string) $community->getIdentifier()],
        );

        return $demoted;
    }

    #[\Override]
    public function kickMember(Community $community, User $target): void
    {
        $this->security->throwAccessDeniedUnlessCommunityModOrAdmin($community, 'You do not have permission to remove members from this community.');

        $member = $this->communityMembership->findOneByUserAndCommunity($target, $community);
        if ($member) {
            $this->removeAndFlush($member);
            $this->removeAccessResidue($target, $community);
            $this->communityPinService->unpinFor($target, $community);
            $this->realtimePublisher->publishCommunityStructureChanged($community);
            $this->realtimePublisher->publishUserEvent(
                (int) $target->getId(),
                'community.removed',
                ['communityIdentifier' => (string) $community->getIdentifier()],
            );
            $this->messageBus->dispatch(new DisconnectVoiceParticipantMessage(
                (int) $target->getId(),
                communityIdentifier: $community->getIdentifier(),
            ));
        }
    }

    #[\Override]
    public function setLogo(Community $community, MediaObject $logo): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'You do not have permission to update this community\'s logo.');
        $oldLogo = $community->getLogo();
        $this->mediaObjectService->prepare($logo, 'logo');
        $community->setLogo($logo);
        $this->save($community);

        // Delete the old logo only after the FK repoint is flushed — deleting a still-referenced MediaObject hits a RESTRICT FK violation.
        if (null !== $oldLogo && $oldLogo !== $logo) {
            $this->mediaObjectService->delete($oldLogo);
        }
        // No structure-publish here — purge the cached community detail directly.
        $this->cachePurger->purgeCommunityDetail((string) $community->getIdentifier());
    }

    #[\Override]
    public function removeLogo(Community $community): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'You do not have permission to update this community\'s logo.');
        $logo = $community->getLogo();
        if (null === $logo) {
            return;
        }
        $community->setLogo(null);
        $this->mediaObjectService->delete($logo);

        $this->save($community);
        $this->cachePurger->purgeCommunityDetail((string) $community->getIdentifier());
    }

    /**
     * @return array<array{id: int, identifier: string, name: string, channelCount: int, memberCount: int, messageCount: int, attachmentCount: int, attachmentsSize: int}>
     */
    #[\Override]
    public function findAllWithStats(): array
    {
        $this->security->throwAccessDeniedUnlessAdmin('Only admins can view community stats.');

        return $this->communityRepository->findWithStats();
    }
}
