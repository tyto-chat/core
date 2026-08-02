<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Security\PermissionResolverInterface;
use App\Serializer\CommunityNormalizer;
use App\Service\Community\CommunityMembershipServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AllowMockObjectsWithoutExpectations]
class CommunityNormalizerTest extends TestCase
{
    private Security&MockObject $security;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private PermissionResolverInterface&MockObject $permissions;
    private NormalizerInterface&MockObject $innerNormalizer;
    private CommunityNormalizer $normalizer;

    #[\Override]
    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->permissions = $this->createMock(PermissionResolverInterface::class);
        $this->innerNormalizer = $this->createMock(NormalizerInterface::class);

        $this->normalizer = new CommunityNormalizer(
            $this->security,
            $this->communityMembershipService,
            $this->permissions,
            voiceEnabled: true,
        );
        $this->normalizer->setNormalizer($this->innerNormalizer);
    }

    private function community(): Community
    {
        $community = new Community();
        new \ReflectionProperty(Community::class, 'id')->setValue($community, 1);

        return $community;
    }

    public function testSupportsCommunityWithoutAlreadyCalledFlag(): void
    {
        self::assertTrue($this->normalizer->supportsNormalization($this->community()));
    }

    public function testDoesNotSupportWhenAlreadyCalledFlagSet(): void
    {
        self::assertFalse(
            $this->normalizer->supportsNormalization($this->community(), null, ['COMMUNITY_NORMALIZER_ALREADY_CALLED' => true])
        );
    }

    public function testDoesNotSupportNonCommunityObject(): void
    {
        self::assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testPublicChannelsNotFilteredForRegularMember(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->innerNormalizer->method('normalize')->willReturn([
            'id' => 1,
            'channels' => [
                ['id' => 10, 'isPrivate' => false],
                ['id' => 11, 'isPrivate' => false],
            ],
        ]);

        $result = $this->normalizer->normalize($this->community());

        self::assertCount(2, $result['channels']);
    }

    public function testPrivateChannelIncludedForChannelMember(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('directChannelRoles')->willReturn([42 => ChannelRole::Member]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $community = $this->community();

        $this->innerNormalizer->method('normalize')->willReturn([
            'id' => 1,
            'channels' => [['id' => 42, 'isPrivate' => true]],
        ]);

        $result = $this->normalizer->normalize($community);

        self::assertCount(1, $result['channels']);
    }

    public function testPrivateChannelExcludedForNonChannelMember(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $community = $this->community();

        $this->innerNormalizer->method('normalize')->willReturn([
            'id' => 1,
            'channels' => [['id' => 42, 'isPrivate' => true]],
        ]);

        $result = $this->normalizer->normalize($community);

        self::assertCount(0, $result['channels']);
    }

    public function testAdminSeesAllChannelsWithoutFiltering(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);
        $this->permissions->expects(self::never())->method('directChannelRoles');

        $this->innerNormalizer->method('normalize')->willReturn([
            'id' => 1,
            'channels' => [
                ['id' => 1, 'isPrivate' => true],
                ['id' => 2, 'isPrivate' => true],
            ],
        ]);

        $result = $this->normalizer->normalize($this->community());

        self::assertCount(2, $result['channels']);
    }

    public function testAudioChannelsFilteredWhenVoiceDisabled(): void
    {
        // Voice-disabled normalizer; admin so the private-channel filter is skipped
        // and we isolate the audio filter (which must apply even to admins).
        $normalizer = new CommunityNormalizer(
            $this->security,
            $this->communityMembershipService,
            $this->permissions,
            voiceEnabled: false,
        );
        $normalizer->setNormalizer($this->innerNormalizer);

        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(true);
        $this->innerNormalizer->method('normalize')->willReturn([
            'id' => 1,
            'channels' => [
                ['id' => 10, 'isPrivate' => false, 'type' => 'text'],
                ['id' => 11, 'isPrivate' => false, 'type' => 'audio'],
            ],
        ]);

        $result = $normalizer->normalize($this->community());

        self::assertCount(1, $result['channels']);
        self::assertSame('text', $result['channels'][0]['type']);
    }
}
