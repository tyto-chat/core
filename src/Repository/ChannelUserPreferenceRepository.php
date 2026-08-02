<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Channel;
use App\Entity\ChannelUserPreference;
use App\Entity\User;
use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Channel\ChannelPinState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelUserPreference>
 */
class ChannelUserPreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelUserPreference::class);
    }

    public function findForUser(Channel $channel, User $user): ?ChannelUserPreference
    {
        return $this->findOneBy(['channel' => $channel, 'user' => $user]);
    }

    /**
     * @return array<int, array{level: ChannelNotificationLevel, pinState: ?ChannelPinState}>
     */
    public function findAllForUser(User $user): array
    {
        /** @var array<int, ChannelUserPreference> $rows */
        $rows = $this->createQueryBuilder('p')
            ->where('p.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $cid = $row->getChannel()->getId();
            if (null !== $cid) {
                $out[$cid] = ['level' => $row->getLevel(), 'pinState' => $row->getPinState()];
            }
        }

        return $out;
    }
}
