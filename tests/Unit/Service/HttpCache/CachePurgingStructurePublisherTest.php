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

        return $community;
    }

    public function testStructureChangedPurgesCommunityDetail(): void
    {
        $community = $this->makeCommunity('comm');

        $this->inner->expects(self::once())->method('publishCommunityStructureChanged')->with($community);
        $this->purger->expects(self::once())->method('purgeCommunityDetail')->with('comm');

        $this->decorator->publishCommunityStructureChanged($community);
    }

    public function testEmojisUpdatedPassesThroughWithoutPurging(): void
    {
        $community = $this->makeCommunity('comm');

        $this->inner->expects(self::once())->method('publishCommunityEmojisUpdated')->with($community);
        $this->purger->expects(self::never())->method('purgeCommunityDetail');

        $this->decorator->publishCommunityEmojisUpdated($community);
    }

    public function testUserEventPassesThroughWithoutPurging(): void
    {
        $this->inner->expects(self::once())->method('publishUserEvent')->with(9, 'role.changed', ['communityIdentifier' => 'comm']);
        $this->purger->expects(self::never())->method('purgeCommunityDetail');

        $this->decorator->publishUserEvent(9, 'role.changed', ['communityIdentifier' => 'comm']);
    }
}
