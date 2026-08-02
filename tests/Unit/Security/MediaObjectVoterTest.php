<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\MediaObject;
use App\Entity\Message;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\User\UserRole;
use App\Security\Voter\ChannelVoter;
use App\Security\Voter\MediaObjectVoter;
use App\Service\Community\CommunityMembershipServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\User\ProfileServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[AllowMockObjectsWithoutExpectations]
class MediaObjectVoterTest extends TestCase
{
    private ProfileServiceInterface&MockObject $profileService;
    private CommunityServiceInterface&MockObject $communityService;
    private CommunityMembershipServiceInterface&MockObject $communityMembershipService;
    private ConversationServiceInterface&MockObject $conversationService;
    private AccessDecisionManagerInterface&MockObject $accessDecisionManager;
    private \App\Service\Community\CommunityEmojiServiceInterface&MockObject $communityEmojiService;
    private MediaObjectVoter $voter;

    #[\Override]
    protected function setUp(): void
    {
        $this->profileService = $this->createMock(ProfileServiceInterface::class);
        $this->communityService = $this->createMock(CommunityServiceInterface::class);
        $this->communityMembershipService = $this->createMock(CommunityMembershipServiceInterface::class);
        $this->conversationService = $this->createMock(ConversationServiceInterface::class);
        $this->accessDecisionManager = $this->createMock(AccessDecisionManagerInterface::class);
        $this->communityEmojiService = $this->createMock(\App\Service\Community\CommunityEmojiServiceInterface::class);
        $this->voter = new MediaObjectVoter(
            $this->profileService,
            $this->communityService,
            $this->communityMembershipService,
            $this->conversationService,
            $this->accessDecisionManager,
            $this->communityEmojiService,
        );
    }

    private function tokenFor(User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function anonToken(): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        return $token;
    }

    private int $nextUserId = 1;

    private function regularUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn([UserRole::User->value]);
        $user->method('getId')->willReturn($this->nextUserId++);

        return $user;
    }

    private function adminUser(): User
    {
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn([UserRole::Admin->value]);
        $user->method('getId')->willReturn($this->nextUserId++);

        return $user;
    }

    private function community(bool $isPrivate): Community
    {
        $community = $this->createMock(Community::class);
        $community->method('isPrivate')->willReturn($isPrivate);

        return $community;
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new MediaObject(), ['UNSUPPORTED'])
        );
    }

    public function testAbstainsForNonMediaObjectSubject(): void
    {
        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($this->anonToken(), new \stdClass(), [MediaObjectVoter::VIEW])
        );
    }

    public function testGrantsViewToAdminRegardlessOfContext(): void
    {
        $this->profileService->expects(self::never())->method('findByAvatar');
        $this->communityService->expects(self::never())->method('findByLogo');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->adminUser()), new MediaObject(), [MediaObjectVoter::VIEW])
        );
    }

    public function testGrantsViewForAvatarToAnonymousUser(): void
    {
        $media = new MediaObject();
        $media->type = 'avatar';
        $this->profileService->expects(self::once())->method('findByAvatar')->with($media)->willReturn(new Profile());
        $this->communityService->expects(self::never())->method('findByLogo');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->anonToken(), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testGrantsViewForAvatarToRegularUser(): void
    {
        $media = new MediaObject();
        $media->type = 'avatar';
        $this->profileService->method('findByAvatar')->willReturn(new Profile());

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->regularUser()), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testGrantsViewForPublicCommunityLogoToAnyone(): void
    {
        $media = new MediaObject();
        $media->type = 'logo';
        $this->communityService->expects(self::once())->method('findByLogo')->with($media)->willReturn($this->community(false));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->anonToken(), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testDeniesViewForPrivateCommunityLogoToAnonymousUser(): void
    {
        $media = new MediaObject();
        $media->type = 'logo';
        $this->communityService->method('findByLogo')->willReturn($this->community(true));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testDeniesViewForPrivateCommunityLogoToNonMember(): void
    {
        $media = new MediaObject();
        $media->type = 'logo';
        $community = $this->community(true);
        $this->communityService->method('findByLogo')->willReturn($community);
        $this->communityMembershipService->method('isMember')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->regularUser()), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testGrantsViewForPrivateCommunityLogoToCommunityMember(): void
    {
        $media = new MediaObject();
        $media->type = 'logo';
        $community = $this->community(true);
        $this->communityService->method('findByLogo')->willReturn($community);
        $this->communityMembershipService->method('isMember')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->regularUser()), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testDeniesViewWhenMediaIsNeitherAvatarNorLogo(): void
    {
        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->regularUser()), new MediaObject(), [MediaObjectVoter::VIEW])
        );
    }

    public function testDeniesViewForUnrelatedMediaToAnonymousUser(): void
    {
        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), new MediaObject(), [MediaObjectVoter::VIEW])
        );
    }

    public function testGrantsViewForPendingAttachmentToOwner(): void
    {
        $user = $this->regularUser();

        $media = new MediaObject();
        $media->type = 'attachment';
        // message is null — pending attachment
        $this->setCreatedBy($media, $user);

        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($user), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testDeniesViewForPendingAttachmentToOtherUser(): void
    {
        $owner = $this->regularUser();
        $other = $this->regularUser();

        $media = new MediaObject();
        $media->type = 'attachment';
        $this->setCreatedBy($media, $owner);

        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($other), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testGrantsViewForLinkedChannelAttachmentWhenChannelVoterGrants(): void
    {
        $channel = $this->createMock(Channel::class);
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        $media = new MediaObject();
        $media->type = 'attachment';
        $media->message = $message;
        $this->setCreatedBy($media, $this->regularUser());

        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);
        $this->accessDecisionManager->expects(self::once())
            ->method('decide')
            ->with(self::anything(), [ChannelVoter::VIEW], $channel)
            ->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($this->regularUser()), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testDeniesViewForLinkedChannelAttachmentWhenChannelVoterDenies(): void
    {
        $channel = $this->createMock(Channel::class);
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        $media = new MediaObject();
        $media->type = 'attachment';
        $media->message = $message;
        $this->setCreatedBy($media, $this->regularUser());

        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);
        $this->accessDecisionManager->method('decide')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($this->regularUser()), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testDelegatesLinkedChannelAttachmentViewForAnonymousToken(): void
    {
        $channel = $this->createMock(Channel::class);
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        $media = new MediaObject();
        $media->type = 'attachment';
        $media->message = $message;
        $this->setCreatedBy($media, $this->regularUser());

        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);
        $this->accessDecisionManager->expects(self::once())
            ->method('decide')
            ->with(self::anything(), [ChannelVoter::VIEW], $channel)
            ->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->anonToken(), $media, [MediaObjectVoter::VIEW])
        );
    }

    public function testDeniesViewForPendingAttachmentToAnonymousUser(): void
    {
        $media = new MediaObject();
        $media->type = 'attachment';
        // message is null — pending attachment, not yet linked
        $this->setCreatedBy($media, $this->regularUser());

        $this->profileService->method('findByAvatar')->willReturn(null);
        $this->communityService->method('findByLogo')->willReturn(null);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $media, [MediaObjectVoter::VIEW])
        );
    }

    /**
     * Use reflection to set the blameable createdBy field on entities
     * (it has no public setter — managed by Gedmo listener).
     */
    private function setCreatedBy(object $entity, ?User $user): void
    {
        $ref = new \ReflectionProperty($entity, 'createdBy');
        $ref->setValue($entity, $user);
    }

    public function testDeniesDeleteToAnonymous(): void
    {
        $media = new MediaObject();
        $media->type = 'attachment';

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->anonToken(), $media, [MediaObjectVoter::DELETE])
        );
    }

    public function testGrantsDeleteOfPendingAttachmentToOwner(): void
    {
        $owner = $this->regularUser();

        $media = new MediaObject();
        $media->type = 'attachment';
        // message null => pending
        $this->setCreatedBy($media, $owner);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($owner), $media, [MediaObjectVoter::DELETE])
        );
    }

    public function testDeniesDeleteOfPendingAttachmentToOtherUser(): void
    {
        $owner = $this->regularUser();
        $other = $this->regularUser();

        $media = new MediaObject();
        $media->type = 'attachment';
        $this->setCreatedBy($media, $owner);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($other), $media, [MediaObjectVoter::DELETE])
        );
    }

    public function testGrantsDeleteOfLinkedAttachmentToOwner(): void
    {
        $owner = $this->regularUser();

        $message = $this->createMock(Message::class);

        $media = new MediaObject();
        $media->type = 'attachment';
        $media->message = $message;
        $this->setCreatedBy($media, $owner);

        // Owner branch is checked before delegating to access decision manager,
        // so no ChannelVoter::MODERATE call should happen.
        $this->accessDecisionManager->expects(self::never())->method('decide');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($owner), $media, [MediaObjectVoter::DELETE])
        );
    }

    public function testGrantsDeleteOfLinkedAttachmentToChannelModerator(): void
    {
        $owner = $this->regularUser();
        $moderator = $this->regularUser();

        $channel = $this->createMock(Channel::class);
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        $media = new MediaObject();
        $media->type = 'attachment';
        $media->message = $message;
        $this->setCreatedBy($media, $owner);

        $this->accessDecisionManager->method('decide')->willReturn(true);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($this->tokenFor($moderator), $media, [MediaObjectVoter::DELETE])
        );
    }

    public function testDeniesDeleteOfLinkedAttachmentToNonModerator(): void
    {
        $owner = $this->regularUser();
        $other = $this->regularUser();

        $channel = $this->createMock(Channel::class);
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        $media = new MediaObject();
        $media->type = 'attachment';
        $media->message = $message;
        $this->setCreatedBy($media, $owner);

        $this->accessDecisionManager->method('decide')->willReturn(false);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($this->tokenFor($other), $media, [MediaObjectVoter::DELETE])
        );
    }
}
