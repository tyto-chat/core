<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\ChannelSection;
use App\Exception\ChannelSection\ChannelSectionNotFoundException;
use App\Repository\ChannelSectionRepository;
use App\Security\SecurityContext;
use App\Service\Channel\ChannelSectionService;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Realtime\RealtimePublisherInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
class ChannelSectionServiceTest extends TestCase
{
    private ChannelSectionRepository&MockObject $channelSectionRepository;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private EntityManagerInterface&MockObject $entityManager;
    private Security&MockObject $security;
    private RealtimePublisherInterface&MockObject $realtimePublisher;
    private ChannelSectionService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->channelSectionRepository = $this->createMock(ChannelSectionRepository::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->realtimePublisher = $this->createMock(RealtimePublisherInterface::class);

        $this->service = new ChannelSectionService(new SecurityContext($this->security, $this->communityMembershipService), $this->channelSectionRepository, $this->realtimePublisher);
        $this->service->setEntityManager($this->entityManager);
        $this->service->setLogger(new NullLogger());
    }

    public function testNewThrowsForNonAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $this->communityMembershipService->method('isAdmin')->willReturn(false);

        $dto = new \App\Dto\ChannelSection\CreateChannelSectionDto('General');

        $this->expectException(AccessDeniedException::class);
        $this->service->new($this->createMock(\App\Entity\Community::class), $dto);
    }

    public function testNewCreatesAndSavesChannelSection(): void
    {
        $this->security->method('isGranted')->willReturn(true);

        $this->entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ChannelSection::class));
        $this->entityManager->expects(self::once())->method('flush');

        $dto = new \App\Dto\ChannelSection\CreateChannelSectionDto('General');
        $this->service->new($this->createMock(\App\Entity\Community::class), $dto);
    }

    public function testDeleteThrowsForNonAdmin(): void
    {
        $this->security->method('isGranted')->willReturn(false);
        $section = $this->createMock(ChannelSection::class);

        $this->expectException(AccessDeniedException::class);
        $this->service->delete($section);
    }

    public function testDeleteThrowsWhenSectionHasChannels(): void
    {
        $this->security->method('isGranted')->willReturn(true);

        $section = $this->createMock(ChannelSection::class);
        $channel = $this->createMock(Channel::class);
        $section->method('getChannels')->willReturn(new ArrayCollection([$channel]));

        $this->expectException(\App\Exception\ChannelSection\CannotDeleteNonEmptySectionException::class);
        $this->service->delete($section);
    }

    public function testDeleteRemovesSectionWhenEmpty(): void
    {
        $this->security->method('isGranted')->willReturn(true);

        $section = $this->createMock(ChannelSection::class);
        $section->method('getChannels')->willReturn(new ArrayCollection());

        $this->entityManager->expects(self::once())->method('remove')->with($section);
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->delete($section);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $this->channelSectionRepository->method('findOneBy')->willReturn(null);

        $this->expectException(ChannelSectionNotFoundException::class);
        $this->service->get(999);
    }

    public function testGetThrowsAccessDeniedWhenNotGranted(): void
    {
        $community = $this->createMock(\App\Entity\Community::class);
        $section = $this->createMock(ChannelSection::class);
        $section->method('getCommunity')->willReturn($community);

        $this->channelSectionRepository->method('findOneBy')->willReturn($section);
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);
        $this->service->get(1);
    }

    public function testGetMapsAuthenticatedViewDenialToNotFound(): void
    {
        $community = $this->createMock(\App\Entity\Community::class);
        $section = $this->createMock(ChannelSection::class);
        $section->method('getCommunity')->willReturn($community);

        $this->channelSectionRepository->method('findOneBy')->willReturn($section);
        $this->security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => 'ROLE_USER' === $attribute,
        );

        $this->expectException(ChannelSectionNotFoundException::class);
        $this->service->get(1);
    }

    public function testGetReturnsSectionWhenGranted(): void
    {
        $community = $this->createMock(\App\Entity\Community::class);
        $section = $this->createMock(ChannelSection::class);
        $section->method('getCommunity')->willReturn($community);

        $this->channelSectionRepository->method('findOneBy')->willReturn($section);
        $this->security->method('isGranted')->willReturn(true);

        self::assertSame($section, $this->service->get(1));
    }

    public function testReorderSectionsPublishesStructure(): void
    {
        $community = $this->createMock(\App\Entity\Community::class);
        $section = $this->createMock(ChannelSection::class);
        $section->method('getId')->willReturn(1);
        $this->channelSectionRepository->method('findBy')->willReturn([$section]);
        $this->security->method('isGranted')->willReturn(true);

        $this->realtimePublisher->expects(self::once())
            ->method('publishCommunityStructureChanged')
            ->with($community);

        $this->service->reorderSections($community, [1]);
    }

    public function testReorderChannelsPublishesStructure(): void
    {
        $community = $this->createMock(\App\Entity\Community::class);
        $channel = $this->createMock(Channel::class);
        $channel->method('getId')->willReturn(1);
        $section = $this->createMock(ChannelSection::class);
        $section->method('getChannels')->willReturn(new ArrayCollection([$channel]));
        $section->method('getCommunity')->willReturn($community);
        $this->security->method('isGranted')->willReturn(true);

        $this->realtimePublisher->expects(self::once())
            ->method('publishCommunityStructureChanged')
            ->with($community);

        $this->service->reorderChannels($section, [1]);
    }
}
