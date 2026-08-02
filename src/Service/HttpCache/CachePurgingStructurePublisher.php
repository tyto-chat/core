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
    public function __construct(
        private readonly StructureRealtimePublisherInterface $inner,
        private readonly CachePurgerInterface $purger,
    ) {
    }

    #[\Override]
    public function publishCommunityStructureChanged(Community $community): void
    {
        $this->inner->publishCommunityStructureChanged($community);
        $this->purger->purgeCommunityDetail((string) $community->getIdentifier());
    }

    #[\Override]
    public function publishUserEvent(int $userId, string $event, array $payload = []): void
    {
        $this->inner->publishUserEvent($userId, $event, $payload);
    }

    #[\Override]
    public function publishCommunityEmojisUpdated(Community $community): void
    {
        $this->inner->publishCommunityEmojisUpdated($community);
    }
}
