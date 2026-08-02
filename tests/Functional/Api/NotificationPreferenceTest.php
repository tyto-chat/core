<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ChannelUserPreference;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Channel\ChannelPinState;
use App\Repository\ChannelUserPreferenceRepository;
use App\Repository\CommunityMemberRepository;
use App\Repository\NotificationRepository;
use App\Service\Notification\ChannelUserPreferenceServiceInterface;
use App\Service\UserContextServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class NotificationPreferenceTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return list<array{type: string, messageCount: int, actorIds: list<int>|null}> */
    private function notifSummary(User $recipient, Community $community): array
    {
        $repo = static::getContainer()->get(NotificationRepository::class);
        \assert($repo instanceof NotificationRepository);

        return array_values(array_map(
            static fn ($n): array => [
                'type' => $n->getType()->value,
                'messageCount' => $n->getMessageCount(),
                'actorIds' => $n->getActorIds(),
            ],
            $repo->findByRecipientAndCommunity($recipient, $community),
        ));
    }

    private function send(User $author, string $community, string $channel, string $text): void
    {
        $this->jsonClient($author)->request(
            'POST',
            "/api/v1/communities/{$community}/channels/{$channel}/messages",
            ['json' => ['text' => $text]],
        );
    }

    public function testDefaultMentionLevelStillDeliversDirectMention(): void
    {
        $author = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np1')->create();
        foreach ([$author, $recipient] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->send($author, 'np1', 'general', "hey [@r](user:{$recipient->getId()})");

        self::assertResponseIsSuccessful();
        $rows = $this->notifSummary($recipient, $community);
        self::assertCount(1, $rows);
        self::assertSame('mention', $rows[0]['type']);
    }

    public function testLevelNoneDropsDirectMention(): void
    {
        $author = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np2')->create();
        foreach ([$author, $recipient] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->setChannelLevel($recipient, $channel, ChannelNotificationLevel::None);

        $this->send($author, 'np2', 'general', "hey [@r](user:{$recipient->getId()})");

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->notifSummary($recipient, $community));
    }

    public function testLevelAllCoalescesPlainMessages(): void
    {
        $author1 = UserFactory::createOne();
        $author2 = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np3')->create();
        foreach ([$author1, $author2, $recipient] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->setChannelLevel($recipient, $channel, ChannelNotificationLevel::All);

        $this->send($author1, 'np3', 'general', 'first');
        self::assertResponseIsSuccessful();
        $this->send($author1, 'np3', 'general', 'second from same author');
        self::assertResponseIsSuccessful();
        $this->send($author2, 'np3', 'general', 'third from new author');
        self::assertResponseIsSuccessful();

        $rows = $this->notifSummary($recipient, $community);
        self::assertCount(1, $rows, 'three plain messages must coalesce into one row');
        self::assertSame('channel_activity', $rows[0]['type']);
        self::assertSame(3, $rows[0]['messageCount']);
        self::assertEqualsCanonicalizing([$author1->getId(), $author2->getId()], $rows[0]['actorIds']);
    }

    public function testCommunityMuteSuppressesAllNotifications(): void
    {
        $author = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np4')->create();
        foreach ([$author, $recipient] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $this->setChannelLevel($recipient, $channel, ChannelNotificationLevel::All);
        $this->muteCommunity($recipient, $community);

        $this->send($author, 'np4', 'general', "ping [@r](user:{$recipient->getId()})");
        self::assertResponseIsSuccessful();
        $this->send($author, 'np4', 'general', 'plain follow-up');
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->notifSummary($recipient, $community));
    }

    public function testActiveChannelSuppressesPlainNotification(): void
    {
        $author = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np5')->create();
        foreach ([$author, $recipient] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $this->setChannelLevel($recipient, $channel, ChannelNotificationLevel::All);

        // Recipient marks the channel read — within the 60s active window.
        $this->jsonClient($recipient)->request(
            'POST',
            '/api/v1/communities/np5/channels/general/mark-read',
        );

        $this->send($author, 'np5', 'general', 'first while active');
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->notifSummary($recipient, $community));
    }

    public function testUnreadQueryRespectsLevelNone(): void
    {
        $author = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np6')->create();
        foreach ([$author, $recipient] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $this->setChannelLevel($recipient, $channel, ChannelNotificationLevel::None);

        $this->send($author, 'np6', 'general', 'plain');
        self::assertResponseIsSuccessful();

        $client = $this->jsonClient($recipient);
        $client->request('GET', '/api/v1/communities/np6/unread-channels');
        self::assertResponseIsSuccessful();
        self::assertSame([], $client->getResponse()->toArray()['unread']);
    }

    public function testUnreadQueryRespectsCommunityMute(): void
    {
        $author = UserFactory::createOne();
        $recipient = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np7')->create();
        foreach ([$author, $recipient] as $u) {
            CommunityMemberFactory::createForUserAndCommunity($u, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $this->muteCommunity($recipient, $community);

        $this->send($author, 'np7', 'general', 'plain');
        self::assertResponseIsSuccessful();

        $client = $this->jsonClient($recipient);
        $client->request('GET', '/api/v1/communities/np7/unread-channels');
        self::assertResponseIsSuccessful();
        self::assertSame([], $client->getResponse()->toArray()['unread']);
    }

    public function testSetChannelLevelEndpointPersistsAndClears(): void
    {
        $caller = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np8')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $client = $this->plainJsonClient($caller);
        $client->request('PUT', '/api/v1/communities/np8/channels/general/notification-preference', [
            'json' => ['level' => 'all'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('all', $client->getResponse()->toArray()['level']);

        $client->request('PUT', '/api/v1/communities/np8/channels/general/notification-preference', [
            'json' => ['level' => null],
        ]);
        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->toArray()['level']);
    }

    public function testNonMemberIsNotNotifiedOnMention(): void
    {
        // A user who is not a member of the community must not receive any
        // notification even when explicitly @mentioned in one of its channels.
        $author = UserFactory::createOne();
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np10')->create();
        CommunityMemberFactory::createForUserAndCommunity($author, $community);
        // $outsider intentionally NOT added as a member.
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->send($author, 'np10', 'general', "hey [@o](user:{$outsider->getId()})");

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->notifSummary($outsider, $community));
    }

    public function testSetCommunityMuteRejectsNonMember(): void
    {
        $caller = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np11')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->plainJsonClient($caller)->request('PUT', '/api/v1/communities/np11/notification-preference', [
            'json' => ['muted' => true],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testGetAllForCurrentUserAggregates(): void
    {
        $caller = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np9')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();
        $this->setChannelLevel($caller, $channel, ChannelNotificationLevel::All);
        $this->muteCommunity($caller, $community);

        $client = $this->plainJsonClient($caller);
        $client->request('GET', '/api/v1/me/notification-preferences');
        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();

        self::assertCount(1, $body['channels']);
        self::assertSame('all', $body['channels'][0]['level']);
        self::assertSame([$community->getId()], $body['mutedCommunityIds']);
    }

    public function testSnapshotIncludesPinState(): void
    {
        $caller = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np-pin')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $context = static::getContainer()->get(UserContextServiceInterface::class);
        \assert($context instanceof UserContextServiceInterface);
        $service = static::getContainer()->get(ChannelUserPreferenceServiceInterface::class);
        \assert($service instanceof ChannelUserPreferenceServiceInterface);
        $context->runAs($caller, static fn () => $service->setPinState($channel, ChannelPinState::Favorite));

        $client = $this->plainJsonClient($caller);
        $client->request('GET', '/api/v1/me/notification-preferences');
        self::assertResponseIsSuccessful();
        $body = $client->getResponse()->toArray();

        self::assertCount(1, $body['channels']);
        self::assertSame('favorite', $body['channels'][0]['pinState']);
        self::assertSame('mentions', $body['channels'][0]['level']);
    }

    private function setChannelLevel(User $user, \App\Entity\Channel $channel, ChannelNotificationLevel $level): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $repo = static::getContainer()->get(ChannelUserPreferenceRepository::class);
        \assert($repo instanceof ChannelUserPreferenceRepository);

        $row = $repo->findForUser($channel, $user) ?? (new ChannelUserPreference())
            ->setUser($user)
            ->setChannel($channel);
        $row->setLevel($level);
        $em->persist($row);
        $em->flush();
    }

    private function muteCommunity(User $user, Community $community): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $repo = static::getContainer()->get(CommunityMemberRepository::class);
        \assert($repo instanceof CommunityMemberRepository);

        $member = $repo->findOneBy(['user' => $user, 'community' => $community]);
        \assert(null !== $member);
        $member->setNotificationsMuted(true);
        $em->persist($member);
        $em->flush();
    }

    public function testSetChannelLevelDeniedForNonMemberOfPrivateCommunity(): void
    {
        // A non-member of a PRIVATE community cannot see its channels at all, so
        // cannot set a notification preference on them. (A public channel in a
        // public community is viewable by anyone, so that path is intentionally
        // allowed by the CHANNEL_VIEW gate and is not tested here.)
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np-priv-comm')->private()->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $client = $this->plainJsonClient($outsider);
        $client->request('PUT', '/api/v1/communities/np-priv-comm/channels/general/notification-preference', [
            'json' => ['level' => 'all'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSetChannelLevelDeniedForNonMemberOnPrivateChannel(): void
    {
        // A private channel is invisible to a community member without a grant;
        // they must not be able to set a preference on it either.
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('np-private')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);
        ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'secret'])->create();

        $client = $this->plainJsonClient($member);
        $client->request('PUT', '/api/v1/communities/np-private/channels/secret/notification-preference', [
            'json' => ['level' => 'all'],
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
