<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityInvite;

interface CommunityInviteServiceInterface
{
    public function create(Community $community, ?int $maxUses, ?\DateTimeImmutable $expiresAt): CommunityInvite;

    /**
     * @return CommunityInvite[]
     */
    public function listForCommunity(Community $community): array;

    public function getById(int $id, Community $community): CommunityInvite;

    public function revoke(CommunityInvite $invite): void;

    public function previewByToken(string $token): CommunityInvite;

    public function accept(string $token): Community;
}
