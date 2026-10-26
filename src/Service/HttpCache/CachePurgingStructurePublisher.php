<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use App\Entity\Community;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Structure publishers must inject StructureRealtimePublisherInterface — publishing via the RealtimePublisherInterface union bypasses this purge decorator.
 */
#[AsDecorator(decorates: StructureRealtimePublisherInterface::class)]
final class CachePurgingStructurePublisher implements StructureRealtimePublisherInterface
{
    private const array PERMISSION_EVENTS = ['channel.access.granted', 'channel.access.revoked', 'community.removed', 'role.changed', 'server.banned'];

    public function __construct(
        private readonly StructureRealtimePublisherInterface $inner,
        private readonly CachePurgerInterface $purger,
    ) {
    }

    #[\Override]
    public function publishCommunityStructureChanged(Community $community, bool $grantsChanged = false): void
    {
        $this->inner->publishCommunityStructureChanged($community, $grantsChanged);
        $this->purger->purgeCommunityDetail((string) $community->getIdentifier());
        $this->purger->purgeCommunityContext($community->getId());
    }

    #[\Override]
    public function publishUserEvent(int $userId, string $event, array $payload = []): void
    {
        $this->inner->publishUserEvent($userId, $event, $payload);
        if (\in_array($event, self::PERMISSION_EVENTS, true)) {
            $this->purger->purgeUserContext($userId);
        }
    }

    #[\Override]
    public function publishCommunityEmojisUpdated(Community $community): void
    {
        $this->inner->publishCommunityEmojisUpdated($community);
    }
}
