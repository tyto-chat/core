<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Serializer\CommunityMemberNormalizer;
use App\Service\UserGroup\UserGroupServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AllowMockObjectsWithoutExpectations]
class CommunityMemberNormalizerTest extends TestCase
{
    private Security&MockObject $security;
    private UserGroupServiceInterface&MockObject $userGroupService;
    private NormalizerInterface&MockObject $innerNormalizer;
    private CommunityMemberNormalizer $normalizer;

    #[\Override]
    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->userGroupService = $this->createMock(UserGroupServiceInterface::class);
        $this->innerNormalizer = $this->createMock(NormalizerInterface::class);

        $this->normalizer = new CommunityMemberNormalizer($this->security, $this->userGroupService);
        $this->normalizer->setNormalizer($this->innerNormalizer);
    }

    private function member(): CommunityMember
    {
        $community = new Community();
        $ref = new \ReflectionProperty($community, 'id');
        $ref->setValue($community, 1);

        $member = new CommunityMember();
        $member->setCommunity($community);
        $member->setUser(new User());

        return $member;
    }

    public function testSupportsCommunityMemberWithoutAlreadyCalledFlag(): void
    {
        self::assertTrue($this->normalizer->supportsNormalization($this->member()));
    }

    public function testDoesNotSupportWhenAlreadyCalledFlagSet(): void
    {
        self::assertFalse(
            $this->normalizer->supportsNormalization($this->member(), null, ['COMMUNITY_MEMBER_NORMALIZER_ALREADY_CALLED' => true])
        );
    }

    public function testGroupMembershipsLoadedOncePerCommunity(): void
    {
        $this->innerNormalizer->method('normalize')->willReturn([]);
        $this->userGroupService->expects($this->once())
            ->method('findGroupMembershipsIndexedByUser')
            ->willReturn([]);

        $this->normalizer->normalize($this->member());
        $this->normalizer->normalize($this->member());
    }

    public function testResetClearsCacheBetweenRequests(): void
    {
        $this->innerNormalizer->method('normalize')->willReturn([]);
        $this->userGroupService->expects($this->exactly(2))
            ->method('findGroupMembershipsIndexedByUser')
            ->willReturn([]);

        $this->normalizer->normalize($this->member());
        $this->normalizer->reset();
        $this->normalizer->normalize($this->member());
    }
}
