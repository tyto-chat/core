<?php

declare(strict_types=1);

namespace App\Service\Community;

use App\Entity\Community;
use App\Entity\CommunityPin;
use App\Entity\User;
use App\Exception\Community\InvalidPinReorderException;
use App\Repository\CommunityPinRepository;
use App\Security\SecurityContext;
use App\Service\AbstractDoctrineService;

class CommunityPinService extends AbstractDoctrineService implements CommunityPinServiceInterface
{
    public function __construct(
        private readonly SecurityContext $security,
        private readonly CommunityPinRepository $pinRepository,
    ) {
    }

    /** @return CommunityPin[] */
    #[\Override]
    public function listForCurrentUser(): array
    {
        $user = $this->security->currentUser('You must be signed in to view pinned communities.');

        return $this->pinRepository->findByUser($user);
    }

    #[\Override]
    public function pinFor(User $user, Community $community): CommunityPin
    {
        $existing = $this->pinRepository->findOneByUserAndCommunity($user, $community);
        if (null !== $existing) {
            return $existing;
        }

        $nextPosition = ($this->pinRepository->findMaxPosition($user) ?? -1) + 1;

        $pin = new CommunityPin();
        $pin->setUser($user);
        $pin->setCommunity($community);
        $pin->setPosition($nextPosition);

        $this->persist($pin);
        $this->flush();

        return $pin;
    }

    #[\Override]
    public function pinForCurrentUser(Community $community): CommunityPin
    {
        $user = $this->security->currentUser('You must be signed in to pin a community.');

        return $this->pinFor($user, $community);
    }

    #[\Override]
    public function unpin(Community $community): void
    {
        $user = $this->security->currentUser('You must be signed in to unpin a community.');

        $this->unpinFor($user, $community);
    }

    #[\Override]
    public function unpinFor(User $user, Community $community): void
    {
        $pin = $this->pinRepository->findOneByUserAndCommunity($user, $community);
        if (null === $pin) {
            return;
        }

        $this->entityManager->remove($pin);
        $this->flush();
    }

    /** @param int[] $communityIds */
    #[\Override]
    public function reorder(array $communityIds): void
    {
        $user = $this->security->currentUser('You must be signed in to reorder pinned communities.');

        $pins = $this->pinRepository->findByUser($user);
        $byCommunityId = [];
        foreach ($pins as $pin) {
            $byCommunityId[(int) $pin->getCommunity()->getId()] = $pin;
        }

        if (count($communityIds) !== count($byCommunityId)) {
            throw new InvalidPinReorderException('Reorder payload size does not match existing pin count.');
        }
        if (count(array_unique($communityIds)) !== count($communityIds)) {
            throw new InvalidPinReorderException('Reorder payload contains duplicate community ids.');
        }
        foreach ($communityIds as $id) {
            if (!isset($byCommunityId[$id])) {
                throw new InvalidPinReorderException(sprintf('Community %d is not in your pin list.', $id));
            }
        }

        foreach ($communityIds as $newPosition => $id) {
            $byCommunityId[$id]->setPosition($newPosition);
        }

        $this->flush();
    }
}
