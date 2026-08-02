<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enum\Channel\ChannelRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ModerationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function modUrl(string $community, ?int $id = null): string
    {
        $url = '/api/v1/communities/'.$community.'/moderation';
        if (null !== $id) {
            $url .= '/'.$id;
        }

        return $url;
    }

    private function activeUrl(string $community, int $userId): string
    {
        return '/api/v1/communities/'.$community.'/users/'.$userId.'/active-moderation';
    }

    private function notesUrl(string $community, int $userId, ?int $noteId = null): string
    {
        if (null !== $noteId) {
            return '/api/v1/communities/'.$community.'/notes/'.$noteId;
        }

        return '/api/v1/communities/'.$community.'/users/'.$userId.'/notes';
    }

    public function testGlobalModCanWarn(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-warn')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-warn'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
            'reason' => 'Spam',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('warn', $data['type']);
        self::assertSame('Spam', $data['reason']);
        self::assertTrue($data['active']);
    }

    public function testGlobalModCanTimeout(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-timeout')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-timeout'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'reason' => 'Flooding',
            'expiresAt' => $expiresAt,
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('timeout', $data['type']);
        self::assertTrue($data['active']);
    }

    public function testCommunityAdminCanBan(): void
    {
        $admin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-ban')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($admin)->request('POST', $this->modUrl('mod-ban'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'ban',
            'reason' => 'Repeated violations',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('ban', $data['type']);
        self::assertTrue($data['active']);
    }

    public function testCommunityModeratorCannotBan(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-cannot-ban')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-cannot-ban'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'ban',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRegularMemberCannotModerate(): void
    {
        $member = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($member)->request('POST', $this->modUrl('mod-deny'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUnauthenticatedCannotModerate(): void
    {
        $target = UserFactory::createOne();
        CommunityFactory::new()->withIdentifier('mod-anon')->create();

        $this->jsonClient()->request('POST', $this->modUrl('mod-anon'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
        ]]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testCannotModerateGlobalAdmin(): void
    {
        $mod = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('mod-immune-admin')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-immune-admin'), ['json' => [
            'targetUserId' => $admin->getId(),
            'type' => 'warn',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCannotModerateCommunityAdmin(): void
    {
        $mod = UserFactory::createOne();
        $communityAdmin = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-immune-cadmin')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createAdminForCommunity($communityAdmin, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-immune-cadmin'), ['json' => [
            'targetUserId' => $communityAdmin->getId(),
            'type' => 'warn',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTimeoutRequiresExpiresAt(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-timeout-invalid')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-timeout-invalid'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testWarnCannotHaveExpiresAt(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-warn-invalid')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-warn-invalid'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
            'expiresAt' => $expiresAt,
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testChannelModCanTimeoutInTheirChannel(): void
    {
        $channelMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chmod-timeout')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($channelMod, $channel, ChannelRole::Moderator);
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($channelMod)->request('POST', $this->modUrl('chmod-timeout'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
            'channelIdentifier' => 'general',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('general', $data['channelIdentifier']);
    }

    public function testChannelModCannotBan(): void
    {
        $channelMod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('chmod-ban-deny')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($channelMod, $channel, ChannelRole::Moderator);

        $this->jsonClient($channelMod)->request('POST', $this->modUrl('chmod-ban-deny'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'ban',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTimedOutUserCannotSendMessage(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('enforce-timeout')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($mod)->request('POST', $this->modUrl('enforce-timeout'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($target)->request('POST', '/api/v1/communities/enforce-timeout/channels/general/messages', [
            'json' => ['text' => 'Hello?'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testBannedUserCannotJoinCommunity(): void
    {
        $admin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('enforce-ban')->create();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        // Apply ban (also kicks from community)
        $this->jsonClient($admin)->request('POST', $this->modUrl('enforce-ban'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'ban',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($target)->request('POST', '/api/v1/communities/enforce-ban/members');

        self::assertResponseStatusCodeSame(403);
    }

    public function testModCanLiftTimeout(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('lift-timeout')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $response = $this->jsonClient($mod)->request('POST', $this->modUrl('lift-timeout'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);
        $actionId = $response->toArray()['id'];

        $this->jsonClient($mod)->request('DELETE', $this->modUrl('lift-timeout', $actionId));

        self::assertResponseStatusCodeSame(204);
    }

    public function testLiftedTimeoutAllowsMessagingAgain(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('lift-resume')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);
        ChannelMemberFactory::createForUserAndChannel($target, $channel);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $response = $this->jsonClient($mod)->request('POST', $this->modUrl('lift-resume'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);
        $actionId = $response->toArray()['id'];

        $this->jsonClient($mod)->request('DELETE', $this->modUrl('lift-resume', $actionId));
        self::assertResponseStatusCodeSame(204);

        $this->jsonClient($target)->request('POST', '/api/v1/communities/lift-resume/channels/general/messages', [
            'json' => ['text' => 'Back!'],
        ]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testModCanViewModerationLog(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-log')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-log'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
        ]]);

        $response = $this->jsonClient($mod)->request('GET', $this->modUrl('mod-log'));

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        self::assertCount(1, $members);
        self::assertSame('warn', $members[0]['type']);

        // An unknown targetUserId filter must return nothing, not the full log.
        $response = $this->jsonClient($mod)->request('GET', $this->modUrl('mod-log').'?targetUserId=999999');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $response->toArray()['hydra:member']);
    }

    public function testRegularMemberCannotViewLog(): void
    {
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-log-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $this->jsonClient($member)->request('GET', $this->modUrl('mod-log-deny'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testModCanViewActiveModeration(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('mod-active')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format(\DateTimeInterface::ATOM);

        $this->jsonClient($mod)->request('POST', $this->modUrl('mod-active'), ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'timeout',
            'expiresAt' => $expiresAt,
        ]]);

        $response = $this->jsonClient($mod)->request('GET', $this->activeUrl('mod-active', $target->getId()));

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        self::assertCount(1, $members);
        self::assertSame('timeout', $members[0]['type']);
    }

    public function testModCanCreateAndReadNotes(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notes-crud')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request('POST', $this->notesUrl('notes-crud', $target->getId()), ['json' => [
            'content' => 'Warned about spam.',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $response = $this->jsonClient($mod)->request('GET', $this->notesUrl('notes-crud', $target->getId()));
        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];
        self::assertCount(1, $members);
        self::assertSame('Warned about spam.', $members[0]['content']);
    }

    public function testModCanUpdateOwnNote(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notes-update')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $response = $this->jsonClient($mod)->request('POST', $this->notesUrl('notes-update', $target->getId()), ['json' => [
            'content' => 'Original note.',
        ]]);
        $noteId = $response->toArray()['id'];

        $this->jsonClient($mod)->request('PATCH', $this->notesUrl('notes-update', $target->getId(), $noteId), [
            'json' => ['content' => 'Updated note.'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['content' => 'Updated note.']);
    }

    public function testModCanDeleteOwnNote(): void
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notes-delete')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $response = $this->jsonClient($mod)->request('POST', $this->notesUrl('notes-delete', $target->getId()), ['json' => [
            'content' => 'Note to delete.',
        ]]);
        $noteId = $response->toArray()['id'];

        $this->jsonClient($mod)->request('DELETE', $this->notesUrl('notes-delete', $target->getId(), $noteId));

        self::assertResponseStatusCodeSame(204);
    }

    public function testRegularMemberCannotCreateNote(): void
    {
        $member = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notes-deny')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($member)->request('POST', $this->notesUrl('notes-deny', $target->getId()), ['json' => [
            'content' => 'Sneaky note.',
        ]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testModCannotUpdateAnotherModsNote(): void
    {
        $mod1 = UserFactory::createOne();
        $mod2 = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notes-update-deny')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod1, $community);
        CommunityMemberFactory::createModeratorForCommunity($mod2, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $response = $this->jsonClient($mod1)->request('POST', $this->notesUrl('notes-update-deny', $target->getId()), ['json' => [
            'content' => 'Mod1 note.',
        ]]);
        $noteId = $response->toArray()['id'];

        $this->jsonClient($mod2)->request('PATCH', $this->notesUrl('notes-update-deny', $target->getId(), $noteId), [
            'json' => ['content' => 'Overwritten.'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCommunityAdminCanUpdateAnotherModsNote(): void
    {
        $mod = UserFactory::createOne();
        $admin = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('notes-update-admin')->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $response = $this->jsonClient($mod)->request('POST', $this->notesUrl('notes-update-admin', $target->getId()), ['json' => [
            'content' => 'Mod note.',
        ]]);
        $noteId = $response->toArray()['id'];

        $response = $this->jsonClient($admin)->request('PATCH', $this->notesUrl('notes-update-admin', $target->getId(), $noteId), [
            'json' => ['content' => 'Corrected by admin.'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Corrected by admin.', $response->toArray()['content']);
    }
}
