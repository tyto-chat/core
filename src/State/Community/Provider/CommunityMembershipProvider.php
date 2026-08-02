<?php

declare(strict_types=1);

namespace App\State\Community\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Dto\Community\CommunityMembershipDto;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Exception\Community\CommunityNotFoundException;
use App\Repository\CommunityRepository;
use App\Security\Voter\CommunityVoter;
use App\Service\Channel\ChannelMembershipServiceInterface;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\UserGroup\UserGroupServiceInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<CommunityMembershipDto>
 */
final readonly class CommunityMembershipProvider implements ProviderInterface
{
    public function __construct(
        private CommunityRepository $communityRepository,
        private Security $security,
        private CommunityMembershipServiceInterface $membershipService,
        private ChannelMembershipServiceInterface $channelMembershipService,
        private UserGroupServiceInterface $userGroupService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CommunityMembershipDto
    {
        $identifier = (string) $uriVariables['identifier'];
        $community = $this->communityRepository->findOneBy(['identifier' => $identifier]);

        // Denied VIEW maps to 404, not 403 — no existence leak
        if (null === $community || !$this->security->isGranted(CommunityVoter::VIEW, $community)) {
            throw new CommunityNotFoundException(sprintf('Community "%s" not found.', $identifier));
        }

        $dto = new CommunityMembershipDto();

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $dto;
        }

        $dto->role = $this->membershipService->findRole($user, $community)?->value;
        $dto->hasMembership = $this->membershipService->isMember($user, $community);

        $memberRoles = $this->channelMembershipService->findChannelMemberRolesInCommunity($user, $community);
        $groupRoles = $this->userGroupService->getEffectiveChannelRolesInCommunity($user, $community);

        foreach (array_unique([...array_keys($memberRoles), ...array_keys($groupRoles)]) as $channelId) {
            $role = ChannelRole::highest($memberRoles[$channelId] ?? null, $groupRoles[$channelId] ?? null);
            if (null !== $role) {
                $dto->channelRoles[$channelId] = $role->value;
            }
        }

        return $dto;
    }
}
