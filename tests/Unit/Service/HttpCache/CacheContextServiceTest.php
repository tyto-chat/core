<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\HttpCache;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Message;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use App\Enum\User\UserRole;
use App\Repository\CommunityRepository;
use App\Repository\MessageRepository;
use App\Security\PermissionResolverInterface;
use App\Service\HttpCache\CacheContextService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CacheContextServiceTest extends TestCase
{
    private CommunityRepository&MockObject $communities;
    private PermissionResolverInterface&MockObject $permissions;
    private MessageRepository&MockObject $messages;
    private CacheContextService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->communities = $this->createMock(CommunityRepository::class);
        $this->permissions = $this->createMock(PermissionResolverInterface::class);
        $this->messages = $this->createMock(MessageRepository::class);

        $this->service = new CacheContextService($this->communities, $this->permissions, $this->messages);
    }

    private function makeUser(int $id, bool $globalAdmin = false): User
    {
        $user = new User();
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);
        if ($globalAdmin) {
            $user->setRoles([UserRole::Admin->value]);
        }

        return $user;
    }

    private function makeCommunity(int $id, bool $isPrivate = false): Community
    {
        $community = new Community();
        $ref = new \ReflectionProperty(Community::class, 'id');
        $ref->setValue($community, $id);
        $community->setPrivate($isPrivate);

        return $community;
    }

    private function makeChannelInCommunity(Community $community): Channel
    {
        $channel = new Channel();
        $channel->setCommunity($community);

        return $channel;
    }

    private function makeMessageInChannel(Channel $channel): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn($channel);

        return $message;
    }

    private function makeDmMessage(): Message
    {
        $message = $this->createMock(Message::class);
        $message->method('getChannel')->willReturn(null);

        return $message;
    }

    private function noGrants(): void
    {
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);
    }

    public function testAnonymousGetsAnonBucket(): void
    {
        self::assertSame('v1:anon', $this->service->bucketFor(null, '/api/v1/communities/x/channels/y/pages/1'));
    }

    public function testUnparseableUriGetsAnonBucket(): void
    {
        $user = $this->makeUser(1);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/messages/current'));
    }

    public function testQueryStringUriStillBucketsByPath(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1?foo=1'));
    }

    public function testMalformedUriGetsAnonBucket(): void
    {
        $user = $this->makeUser(1);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '//x:y:z/path'));
    }

    public function testUnknownCommunityGetsAnonBucket(): void
    {
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn(null);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/ghost/channels/y/pages/1'));
    }

    public function testPlainMemberOfPublicCommunityGetsStandardBucket(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1'));
    }

    public function testNonMemberOfPublicCommunityGetsStandardBucket(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1'));
    }

    public function testNonMemberOfPrivateCommunityGetsAnonBucket(): void
    {
        $community = $this->makeCommunity(10, true);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1'));
    }

    public function testPlainMemberOfPrivateCommunityGetsStandardBucket(): void
    {
        $community = $this->makeCommunity(10, true);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1'));
    }

    public function testGlobalAdminGetsPerUserFingerprint(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1, true);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testCommunityModeratorGetsPerUserFingerprint(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(true);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testDirectChannelGrantGetsPerUserFingerprint(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([5 => ChannelRole::Moderator]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testGroupDerivedGrantGetsPerUserFingerprint(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([7 => ChannelRole::Member]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testFingerprintChangesWhenGrantAdded(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([5 => ChannelRole::Member]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $before = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1');

        $permissions = $this->createMock(PermissionResolverInterface::class);
        $permissions->method('directChannelRoles')->willReturn([5 => ChannelRole::Member, 6 => ChannelRole::Moderator]);
        $permissions->method('groupChannelRoles')->willReturn([]);
        $this->service = new CacheContextService($this->communities, $permissions, $this->messages);

        $after = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pages/1');

        self::assertNotSame($before, $after);
    }

    public function testTwoElevatedUsersNeverShareABucket(): void
    {
        $community = $this->makeCommunity(10, false);
        $userA = $this->makeUser(1, true);
        $userB = $this->makeUser(2, true);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucketA = $this->service->bucketFor($userA, '/api/v1/communities/x/channels/y/pages/1');
        $bucketB = $this->service->bucketFor($userB, '/api/v1/communities/x/channels/y/pages/1');

        self::assertNotSame($bucketA, $bucketB);
    }

    public function testUnrelatedCommunityPathStaysAnon(): void
    {
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($this->makeCommunity(10, false));
        $this->noGrants();

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/messages'));
    }

    public function testPlainMemberOfPublicCommunityGetsStandardBucketForMessagesCurrent(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/messages/current'));
    }

    public function testPlainMemberOfPublicCommunityGetsStandardBucketForPinnedMessages(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pinned-messages'));
    }

    public function testPlainMemberOfPublicCommunityGetsStandardBucketForEmojis(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/emojis'));
    }

    public function testGlobalAdminGetsPerUserFingerprintForMessagesCurrent(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1, true);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/messages/current');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testGlobalAdminGetsPerUserFingerprintForPinnedMessages(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1, true);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pinned-messages');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testGlobalAdminGetsPerUserFingerprintForEmojis(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1, true);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x/emojis');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testNonMemberOfPrivateCommunityGetsAnonBucketForMessagesCurrent(): void
    {
        $community = $this->makeCommunity(10, true);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/messages/current'));
    }

    public function testNonMemberOfPrivateCommunityGetsAnonBucketForPinnedMessages(): void
    {
        $community = $this->makeCommunity(10, true);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/channels/y/pinned-messages'));
    }

    public function testNonMemberOfPrivateCommunityGetsAnonBucketForEmojis(): void
    {
        $community = $this->makeCommunity(10, true);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/emojis'));
    }

    public function testPercentEncodedKeywordStillBucketsByDecodedPath(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/emoji%73'));
    }

    public function testEncodedSlashInIdentifierStaysAnon(): void
    {
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($this->makeCommunity(10, false));
        $this->noGrants();

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/a%2Fb/emojis'));
    }

    public function testDoubleEncodedKeywordStaysAnon(): void
    {
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($this->makeCommunity(10, false));
        $this->noGrants();

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/emoji%2573'));
    }

    public function testThreadMemberOfRootCommunityGetsStandardBucket(): void
    {
        $community = $this->makeCommunity(10, false);
        $channel = $this->makeChannelInCommunity($community);
        $message = $this->makeMessageInChannel($channel);
        $user = $this->makeUser(1);
        $this->messages->method('findOneBy')->willReturn($message);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/messages/11111111-1111-4111-8111-111111111111/thread'));
    }

    public function testThreadElevatedUserGetsPerUserFingerprint(): void
    {
        $community = $this->makeCommunity(10, false);
        $channel = $this->makeChannelInCommunity($community);
        $message = $this->makeMessageInChannel($channel);
        $user = $this->makeUser(1, true);
        $this->messages->method('findOneBy')->willReturn($message);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/messages/11111111-1111-4111-8111-111111111111/thread');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testThreadUnknownUuidGetsAnonBucket(): void
    {
        $user = $this->makeUser(1);
        $this->messages->method('findOneBy')->willReturn(null);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/messages/11111111-1111-4111-8111-111111111111/thread'));
    }

    public function testThreadOfDmMessageGetsAnonBucket(): void
    {
        $user = $this->makeUser(1);
        $this->messages->method('findOneBy')->willReturn($this->makeDmMessage());

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/messages/11111111-1111-4111-8111-111111111111/thread'));
    }

    public function testThreadPercentEncodedUuidPathStillWorks(): void
    {
        $community = $this->makeCommunity(10, false);
        $channel = $this->makeChannelInCommunity($community);
        $message = $this->makeMessageInChannel($channel);
        $user = $this->makeUser(1);
        $this->messages->method('findOneBy')->willReturn($message);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/messages/11111111-1111-4111-8111-111111%3111111/thread'));
    }

    public function testVersionedMessagePathBucketsLikeUnversionedDid(): void
    {
        $community = $this->makeCommunity(10, false);
        $channel = $this->makeChannelInCommunity($community);
        $message = $this->makeMessageInChannel($channel);
        $user = $this->makeUser(1);
        $this->messages->method('findOneBy')->willReturn($message);
        $this->noGrants();

        $uuid = '11111111-1111-4111-8111-111111111111';

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/messages/'.$uuid));
        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/messages/'.$uuid.'/thread'));
    }

    public function testUnversionedApiPathNoLongerMatchesAnyBucketShape(): void
    {
        $community = $this->makeCommunity(10, false);
        $channel = $this->makeChannelInCommunity($community);
        $message = $this->makeMessageInChannel($channel);
        $user = $this->makeUser(1);
        $this->messages->method('findOneBy')->willReturn($message);
        $this->noGrants();

        // The bare (unversioned) shape must fall through to the ANON default
        // branch, not the message branch — real requests only ever arrive
        // versioned (routing enforces the {version} requirement, task-2), and
        // Caddy's @cacheable regexp won't match this shape either, so it's
        // never actually served from cache; this only guards bucketFor()
        // itself from silently still recognizing the old shape.
        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/messages/11111111-1111-4111-8111-111111111111'));
    }

    public function testPresenceSummaryMemberOfPublicCommunityGetsStandardBucket(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x/presence/summary'));
    }

    public function testPresenceSummaryNonMemberOfPrivateCommunityGetsAnonBucket(): void
    {
        $community = $this->makeCommunity(10, true);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/presence/summary'));
    }

    public function testCommunityDetailMemberOfPublicCommunityGetsStandardBucket(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->noGrants();

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x'));
    }

    public function testCommunityDetailNonMemberOfPublicCommunityGetsStandardBucket(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:C10:standard', $this->service->bucketFor($user, '/api/v1/communities/x'));
    }

    public function testCommunityDetailNonMemberOfPrivateCommunityGetsAnonBucket(): void
    {
        $community = $this->makeCommunity(10, true);
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(false);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x'));
    }

    public function testCommunityDetailElevatedUserGetsPerUserFingerprint(): void
    {
        $community = $this->makeCommunity(10, false);
        $user = $this->makeUser(1, true);
        $this->communities->method('findOneBy')->willReturn($community);
        $this->permissions->method('isMember')->willReturn(true);
        $this->permissions->method('isCommunityAdmin')->willReturn(false);
        $this->permissions->method('isCommunityModerator')->willReturn(false);
        $this->permissions->method('directChannelRoles')->willReturn([]);
        $this->permissions->method('groupChannelRoles')->willReturn([]);

        $bucket = $this->service->bucketFor($user, '/api/v1/communities/x');

        self::assertStringStartsWith('v1:', $bucket);
        self::assertNotSame('v1:anon', $bucket);
        self::assertNotSame('v1:C10:standard', $bucket);
    }

    public function testCommunityDetailWithTrailingSlashStaysAnon(): void
    {
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($this->makeCommunity(10, false));
        $this->noGrants();

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities/x/'));
    }

    public function testCommunitiesCollectionStaysAnon(): void
    {
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($this->makeCommunity(10, false));
        $this->noGrants();

        self::assertSame('v1:anon', $this->service->bucketFor($user, '/api/v1/communities'));
    }

    /**
     * The bare-detail branch of the alternation must NOT swallow uncacheable
     * community subresources — those must stay anon (fail-closed) here AND
     * stay unmatched by the Caddyfile @cacheable regexp (lockstep requirement,
     * covered by testCaddyfileRegexpStaysInLockstep below).
     *
     * @return iterable<string, array{string}>
     */
    public static function uncacheableCommunitySubresources(): iterable
    {
        yield 'membership' => ['/api/v1/communities/x/membership'];
        yield 'invites' => ['/api/v1/communities/x/invites'];
        yield 'moderation' => ['/api/v1/communities/x/moderation'];
        yield 'unread-channels' => ['/api/v1/communities/x/unread-channels'];
        yield 'members' => ['/api/v1/communities/x/members'];
        yield 'channel detail' => ['/api/v1/communities/x/channels/y'];
        yield 'presence online' => ['/api/v1/communities/x/presence/online'];
        yield 'notification preference' => ['/api/v1/communities/x/notification-preference'];
        yield 'channel search' => ['/api/v1/communities/x/channels/y/search'];
        yield 'channel messages POST shape' => ['/api/v1/communities/x/channels/y/messages'];
    }

    #[DataProvider('uncacheableCommunitySubresources')]
    public function testUncacheableCommunitySubresourceStaysAnon(string $path): void
    {
        $user = $this->makeUser(1);
        $this->communities->method('findOneBy')->willReturn($this->makeCommunity(10, false));
        $this->noGrants();

        self::assertSame('v1:anon', $this->service->bucketFor($user, $path));
    }

    /**
     * Caddy parity: the @cacheable path_regexp in docker/frankenphp/Caddyfile
     * must match exactly the shapes bucketFor() recognizes and reject the
     * subresources above — a drift either way silently breaks caching
     * (unmatched here => bucket collapse; matched only there => stale cache
     * with anon bucket). This test parses the regexp straight out of the
     * Caddyfile so the lockstep is enforced, not just documented.
     */
    public function testCaddyfileRegexpStaysInLockstep(): void
    {
        $caddyfile = (string) file_get_contents(__DIR__.'/../../../../docker/frankenphp/Caddyfile');
        self::assertSame(1, preg_match('/@cacheable path_regexp (\S+)/', $caddyfile, $m), 'no @cacheable path_regexp found in Caddyfile');
        $caddyRegexp = '#'.str_replace('#', '\#', $m[1]).'#';

        // Maintenance note: adding a shape to either regexp (the Caddyfile
        // @cacheable path_regexp OR the PHP-side pattern in bucketFor())
        // REQUIRES adding it to these two lists too — otherwise a drift
        // between the two regexps can land undetected: this test only
        // catches what it's told to check.
        $cacheable = [
            '/api/v1/communities/x',
            '/api/v1/communities/x/emojis',
            '/api/v1/communities/x/presence/summary',
            '/api/v1/communities/x/channels/y/pages/3',
            '/api/v1/communities/x/channels/y/messages/current',
            '/api/v1/communities/x/channels/y/pinned-messages',
            '/api/v1/messages/11111111-1111-4111-8111-111111111111/thread',
        ];
        foreach ($cacheable as $path) {
            self::assertSame(1, preg_match($caddyRegexp, $path), sprintf('Caddyfile @cacheable must match %s', $path));
        }

        // Same maintenance note as above: adding a shape to either regexp
        // REQUIRES adding it to these lists too.
        $uncacheable = array_merge(
            array_map(
                static fn (array $case): string => $case[0],
                iterator_to_array(self::uncacheableCommunitySubresources()),
            ),
            [
                '/api/v1/communities',
                '/api/v1/communities/x/',
                // Unversioned shapes must never match either — real requests
                // only ever arrive versioned (task-2), so a bare /api/... path
                // must stay uncacheable at the edge too.
                '/api/communities/x',
                '/api/messages/11111111-1111-4111-8111-111111111111/thread',
            ],
        );
        foreach ($uncacheable as $path) {
            self::assertSame(0, preg_match($caddyRegexp, $path), sprintf('Caddyfile @cacheable must NOT match %s', $path));
        }
    }
}
