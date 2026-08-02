<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityInvite;
use App\Exception\Community\InviteNoLongerValidException;
use App\Exception\Community\InviteNotFoundException;
use App\Repository\CommunityInviteRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;

class CommunityInviteService extends AbstractDoctrineService implements CommunityInviteServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly CommunityInviteRepository $inviteRepository,
        private readonly CommunityServiceInterface $communityService,
    ) {
    }

    #[\Override]
    public function create(Community $community, ?int $maxUses, ?\DateTimeImmutable $expiresAt): CommunityInvite
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'You do not have permission to create invites for this community.');

        $invite = new CommunityInvite();
        $invite->setCommunity($community);
        $invite->setToken($this->generateToken());
        $invite->setCreatedBy($this->security->getUser());
        $invite->setMaxUses($maxUses);
        $invite->setExpiresAt($expiresAt);

        return $this->save($invite);
    }

    /** @return CommunityInvite[] */
    #[\Override]
    public function listForCommunity(Community $community): array
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'You do not have permission to view invites for this community.');

        return $this->inviteRepository->findByCommunity($community);
    }

    #[\Override]
    public function getById(int $id, Community $community): CommunityInvite
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($community, 'You do not have permission to manage invites for this community.');

        $invite = $this->inviteRepository->findOneBy(['id' => $id, 'community' => $community]);
        if (null === $invite) {
            throw new InviteNotFoundException(sprintf('Invite %d not found in this community.', $id));
        }

        return $invite;
    }

    #[\Override]
    public function revoke(CommunityInvite $invite): void
    {
        $this->security->throwAccessDeniedUnlessCommunityAdmin($invite->getCommunity(), 'You do not have permission to revoke this invite.');

        $this->removeAndFlush($invite);
    }

    #[\Override]
    public function previewByToken(string $token): CommunityInvite
    {
        $invite = $this->inviteRepository->findOneByToken($token);
        if (null === $invite) {
            throw new InviteNotFoundException('Invite not found.');
        }
        if (!$invite->isValid()) {
            throw new InviteNoLongerValidException('This invite is no longer valid.');
        }

        return $invite;
    }

    #[\Override]
    public function accept(string $token): Community
    {
        $invite = $this->previewByToken($token);
        $community = $invite->getCommunity();

        $user = $this->security->currentUser('You need to be signed in to accept an invite.');
        if (null !== $this->communityMembership->findOneByUserAndCommunity($user, $community)) {
            return $community;
        }

        // Guarded UPDATE — a plain check-then-increment would let concurrent accepts exceed maxUses.
        if (!$this->inviteRepository->tryConsumeUse($invite)) {
            throw new InviteNoLongerValidException('This invite is no longer valid.');
        }

        try {
            $this->communityService->joinViaInvite($community);
        } catch (\Throwable $e) {
            $this->inviteRepository->refundUse($invite);

            throw $e;
        }

        return $community;
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(16));
    }
}
