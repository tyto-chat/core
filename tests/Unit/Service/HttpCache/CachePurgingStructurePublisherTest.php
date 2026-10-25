<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\HttpCache;

use App\Entity\Community;
use App\Service\HttpCache\CachePurgerInterface;
use App\Service\HttpCache\CachePurgingStructurePublisher;
use App\Service\Realtime\StructureRealtimePublisherInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CachePurgingStructurePublisherTest extends TestCase
{
    private StructureRealtimePublisherInterface&MockObject $inner;
    private CachePurgerInterface&MockObject $purger;
    private CachePurgingStructurePublisher $decorator;

    #[\Override]
    protected function setUp(): void
    {
        $this->inner = $this->createMock(StructureRealtimePublisherInterface::class);
        $this->purger = $this->createMock(CachePurgerInterface::class);
        $this->decorator = new CachePurgingStructurePublisher($this->inner, $this->purger);
    }

    private function makeCommunity(string $identifier): Community
    {
        $community = new Community();
        $community->setIdentifier($identifier);
        (new \ReflectionProperty(Community::class, 'id'))->setValue($community, 7);

        return $community;
    }

    public function testStructureChangedPurgesCommunityDetail(): void
    {
        $community = $this->makeCommunity('comm');

        $this->inner->expects(self::once())->method('publishCommunityStructureChanged')->with($community);
        $this->purger->expects(self::once())->method('purgeCommunityDetail')->with('comm');
        $this->purger->expects(self::once())->method('purgeCommunityContext')->with(7);

        $this->decorator->publishCommunityStructureChanged($community);
    }

    public function testEmojisUpdatedPassesThroughWithoutPurging(): void
    {
        $community = $this->makeCommunity('comm');

        $this->inner->expects(self::once())->method('publishCommunityEmojisUpdated')->with($community);
        $this->purger->expects(self::never())->method('purgeCommunityDetail');

        $this->decorator->publishCommunityEmojisUpdated($community);
    }

    /** @return iterable<string, array{string}> */
    public static function permissionEvents(): iterable
    {
        foreach (['channel.access.granted', 'channel.access.revoked', 'community.removed', 'role.changed', 'server.banned'] as $event) {
            yield $event => [$event];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('permissionEvents')]
    public function testPermissionUserEventsPurgeThatUsersContext(string $event): void
    {
        $this->inner->expects(self::once())->method('publishUserEvent')->with(9, $event, []);
        $this->purger->expects(self::once())->method('purgeUserContext')->with(9);
        $this->purger->expects(self::never())->method('purgeCommunityDetail');

        $this->decorator->publishUserEvent(9, $event);
    }

    public function testOtherUserEventsPassThroughWithoutPurging(): void
    {
        $this->inner->expects(self::once())->method('publishUserEvent')->with(9, 'notification.created', []);
        $this->purger->expects(self::never())->method('purgeUserContext');

        $this->decorator->publishUserEvent(9, 'notification.created');
    }
}
