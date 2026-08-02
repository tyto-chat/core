<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Dto\Community\CreateCommunityDto;
use App\Dto\Community\UpdateCommunityDto;
use App\Dto\ServerInfo\CommunityStatsDto;
use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\MediaObject;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use App\Exception\Community\AlreadyMemberException;
use App\Exception\Community\CommunityNotFoundException;
use App\Exception\Community\NotAMemberException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

interface CommunityServiceInterface
{
    /**
     * @throws AccessDeniedException
     */
    public function new(CreateCommunityDto $createCommunityDto): Community;

    /**
     * @throws AccessDeniedException
     * @throws CommunityNotFoundException
     */
    public function get(int $id): Community;

    public function findByIdentifier(string $identifier): ?Community;

    public function findByLogo(MediaObject $logo): ?Community;

    /**
     * @throws AccessDeniedException
     * @throws CommunityNotFoundException
     */
    public function getByIdentifier(string $identifier): Community;

    /**
     * @return Community[]
     */
    public function getAll(): array;

    /**
     * @return Community[]
     */
    public function getPublic(): array;

    /** @return array<string, CommunityStatsDto> community identifier → public stats */
    public function getPublicStats(): array;

    /**
     * @return CommunityMember[]
     */
    public function getMembers(Community $community): array;

    public function getMember(Community $community, int $memberId): CommunityMember;

    /**
     * @throws AccessDeniedException
     */
    public function updateMemberRole(CommunityMember $member, CommunityRole $role): CommunityMember;

    /**
     * @return array<Community>
     */
    public function getJoined(): array;

    /**
     * @throws AlreadyMemberException
     */
    public function join(Community $community): void;

    /**
     * @throws NotAMemberException
     */
    public function leave(Community $community): void;

    public function addMember(Community $community, User $user, CommunityRole $role = CommunityRole::Member, bool $sendWelcome = true): CommunityMember;

    /** Bypasses the invite-only gate — caller must have validated the invite token. */
    public function joinViaInvite(Community $community): CommunityMember;

    public function kickMember(Community $community, User $target): void;

    /**
     * @throws AccessDeniedException
     */
    public function update(Community $community, UpdateCommunityDto $updateCommunityDto): Community;

    /**
     * @throws AccessDeniedException
     */
    public function delete(Community $community): void;

    /**
     * No authz here — gated at the API-operation level (ADMIN_COMMUNITY_MANAGE).
     *
     * @return int the number of other admins demoted (0 unless $demoteOthers)
     *
     * @throws NotAMemberException if the target user is not a member
     */
    public function transferAdminRole(Community $community, User $newAdmin, bool $demoteOthers): int;

    public function setLogo(Community $community, MediaObject $logo): void;

    public function removeLogo(Community $community): void;

    /**
     * @return array<array{id: int, identifier: string, name: string, channelCount: int, memberCount: int, messageCount: int, attachmentCount: int, attachmentsSize: int}>
     */
    public function findAllWithStats(): array;
}
