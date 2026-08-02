<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\SamplePresenceMessage;
use App\Entity\PresenceSample;
use App\Enum\Presence\PresenceState;
use App\Repository\CommunityRepository;
use App\Repository\PresenceSampleRepository;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Presence\GuestPresenceServiceInterface;
use App\Service\Presence\PresenceServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SamplePresenceHandler
{
    private const RETENTION_DAYS = 90;

    public function __construct(
        private readonly CommunityRepository $communityRepository,
        private readonly PresenceSampleRepository $sampleRepository,
        private readonly CommunityMembershipServiceInterface $communityMembership,
        private readonly PresenceServiceInterface $presence,
        private readonly GuestPresenceServiceInterface $guestPresence,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SamplePresenceMessage $message): void
    {
        $sampledAt = new \DateTimeImmutable();
        foreach ($this->communityRepository->findAll() as $community) {
            $memberIds = $this->communityMembership->findMemberUserIds($community);
            $membersOnline = 0;
            if ([] !== $memberIds) {
                foreach ($this->presence->getBatch($memberIds) as $snapshot) {
                    if (PresenceState::Offline !== $snapshot->state) {
                        ++$membersOnline;
                    }
                }
            }
            $guests = $community->isPrivate() ? 0 : $this->guestPresence->getGuestCount($community);
            $this->em->persist(new PresenceSample($community, $membersOnline, $guests, $sampledAt));
        }
        $this->em->flush();
        $this->sampleRepository->deleteOlderThan($sampledAt->modify(sprintf('-%d days', self::RETENTION_DAYS)));
    }
}
