<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Community;
use App\Entity\User;
use App\Enum\Community\BroadcastMentionRole;
use App\Repository\NotificationRepository;
use App\Service\Presence\PresenceServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\InMemoryPresenceService;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class BroadcastMentionTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return string[] notification types for the recipient in the community */
    private function notifTypes(User $recipient, Community $community): array
    {
        $repo = static::getContainer()->get(NotificationRepository::class);
        \assert($repo instanceof NotificationRepository);

        return array_map(
            static fn ($n): string => $n->getType()->value,
            $repo->findByRecipientAndCommunity($recipient, $community),
        );
    }

    private function presenceStub(): InMemoryPresenceService
    {
        $svc = static::getContainer()->get(PresenceServiceInterface::class);
        \assert($svc instanceof InMemoryPresenceService);

        return $svc;
    }

    private function send(User $author, string $community, string $channel, string $text): void
    {
        $this->jsonClient($author)->request(
            'POST',
            "/api/v1/communities/{$community}/channels/{$channel}/messages",
            ['json' => ['text' => $text]],
        );
    }

    public function testChannelBroadcastNotifiesPublicChannelAudience(): void
    {
        $author = UserFactory::createOne();
        $m2 = UserFactory::createOne();
        $m3 = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('bc1')->create();
        foreach ([$author, $m2, $m3] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->send($author, 'bc1', 'general', 'Heads up [@channel](broadcast:channel)');

        self::assertResponseIsSuccessful();
        self::assertSame(['broadcast_mention'], $this->notifTypes($m2, $community));
        self::assertSame(['broadcast_mention'], $this->notifTypes($m3, $community));
        self::assertSame([], $this->notifTypes($author, $community), 'author must not be notified');
    }

    public function testChannelBroadcastInPrivateChannelOnlyNotifiesMembers(): void
    {
        $author = UserFactory::createOne();
        $member = UserFactory::createOne();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('bc2')->create();
        foreach ([$author, $member, $outsider] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();
        ChannelMemberFactory::createForUserAndChannel($author, $channel);
        ChannelMemberFactory::createForUserAndChannel($member, $channel);

        $this->send($author, 'bc2', 'secret', 'psst [@channel](broadcast:channel)');

        self::assertResponseIsSuccessful();
        self::assertSame(['broadcast_mention'], $this->notifTypes($member, $community));
        self::assertSame([], $this->notifTypes($outsider, $community), 'non-member must not be notified');
    }

    public function testHereNotifiesOnlyOnlineMembers(): void
    {
        $author = UserFactory::createOne();
        $online = UserFactory::createOne();
        $offline = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('bc3')->create();
        foreach ([$author, $online, $offline] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->presenceStub()->touch($online);

        $this->send($author, 'bc3', 'general', 'who is around [@here](broadcast:here)');

        self::assertResponseIsSuccessful();
        self::assertSame(['broadcast_mention'], $this->notifTypes($online, $community));
        self::assertSame([], $this->notifTypes($offline, $community), 'offline member must not be notified');
    }

    public function testDirectMentionTakesPrecedenceOverBroadcast(): void
    {
        $author = UserFactory::createOne();
        $m2 = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('bc4')->create();
        foreach ([$author, $m2] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->send(
            $author,
            'bc4',
            'general',
            "[@m2](user:{$m2->getId()}) and [@channel](broadcast:channel)",
        );

        self::assertResponseIsSuccessful();
        // Exactly one notification, the direct mention — not also a broadcast one.
        self::assertSame(['mention'], $this->notifTypes($m2, $community));
    }

    public function testMemberBlockedWhenCommunityRequiresModerator(): void
    {
        $author = UserFactory::createOne();
        $m2 = UserFactory::createOne();
        $community = CommunityFactory::new()
            ->withIdentifier('bc5')
            ->with(['broadcastMentionMinRole' => BroadcastMentionRole::Moderator])
            ->create();
        foreach ([$author, $m2] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->send($author, 'bc5', 'general', 'try [@channel](broadcast:channel)');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->notifTypes($m2, $community));
    }

    public function testModeratorAllowedWhenCommunityRequiresModerator(): void
    {
        $author = UserFactory::createOne();
        $m2 = UserFactory::createOne();
        $community = CommunityFactory::new()
            ->withIdentifier('bc6')
            ->with(['broadcastMentionMinRole' => BroadcastMentionRole::Moderator])
            ->create();
        CommunityMemberFactory::createModeratorForCommunity($author, $community);
        CommunityMemberFactory::createForUserAndCommunity($m2, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->send($author, 'bc6', 'general', 'announce [@channel](broadcast:channel)');

        self::assertResponseIsSuccessful();
        self::assertSame(['broadcast_mention'], $this->notifTypes($m2, $community));
    }

    public function testPlainMessageCreatesNoBroadcastNotifications(): void
    {
        $author = UserFactory::createOne();
        $m2 = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('bc7')->create();
        foreach ([$author, $m2] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->send($author, 'bc7', 'general', 'just a normal message');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->notifTypes($m2, $community));
    }
}
