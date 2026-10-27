<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\ChannelUserPreference;
use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Community\CommunityRole;
use App\Repository\CommunityMemberRepository;
use App\Repository\NotificationRepository;
use App\Service\Message\MessageNotificationDispatcherInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class BlockedNotificationTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{Community, Channel} */
    private function communityWith(string $identifier, User ...$users): array
    {
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        foreach ($users as $user) {
            CommunityMemberFactory::createForUserAndCommunity($user, $community);
        }
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        return [$community, $channel];
    }

    private function block(User $blocker, User $target): void
    {
        $this->plainJsonClient($blocker)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $target->getId()]]);
        self::assertResponseStatusCodeSame(201);
    }

    /** @return array<string, mixed> */
    private function send(User $author, string $community, string $text): array
    {
        $response = $this->jsonClient($author)->request(
            'POST',
            "/api/v1/communities/{$community}/channels/general/messages",
            ['json' => ['text' => $text]],
        );
        self::assertResponseIsSuccessful();

        return $response->toArray();
    }

    /** @return list<array<string, mixed>> */
    private function visibleNotifs(User $recipient, string $community): array
    {
        $data = $this->jsonClient($recipient)->request('GET', "/api/v1/communities/{$community}/notifications")->toArray();
        self::assertResponseIsSuccessful();

        return $data['hydra:member'];
    }

    /** @return list<array{type: string, messageCount: int, actorIds: list<int>|null}> */
    private function channelNotifs(User $recipient, Community $community): array
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

    private function dmNotifCount(User $recipient): int
    {
        $repo = static::getContainer()->get(NotificationRepository::class);
        \assert($repo instanceof NotificationRepository);

        return \count($repo->findDmByRecipient($recipient));
    }

    private function mention(User $user): string
    {
        return "[@u](user:{$user->getId()})";
    }

    private function setLevelAll(User $user, Channel $channel): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist((new ChannelUserPreference())->setUser($user)->setChannel($channel)->setLevel(ChannelNotificationLevel::All));
        $em->flush();
    }

    private function groupConversation(User ...$users): Conversation
    {
        $conversation = ConversationFactory::new()->withParticipants($users)->create();
        foreach ($users as $user) {
            ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        }

        return $conversation;
    }

    /** @return array<string, mixed> */
    private function sendDm(User $author, Conversation $conversation, string $text): array
    {
        $response = $this->jsonClient($author)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => $text]],
        );
        self::assertResponseStatusCodeSame(201);

        return $response->toArray();
    }

    public function testNoMentionFromBlockedUser(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        [$community] = $this->communityWith('bn1', $a, $b, $c);
        $this->block($a, $b);

        $this->send($b, 'bn1', 'hi '.$this->mention($a).' '.$this->mention($c));

        self::assertSame([], $this->channelNotifs($a, $community));
        self::assertSame(['mention'], array_column($this->channelNotifs($c, $community), 'type'));
    }

    public function testNoBroadcastMentionFromBlockedUser(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        [$community] = $this->communityWith('bn2', $a, $b, $c);
        $this->block($a, $b);

        $this->send($b, 'bn2', 'Heads up [@channel](broadcast:channel)');

        self::assertSame([], $this->channelNotifs($a, $community));
        self::assertSame(['broadcast_mention'], array_column($this->channelNotifs($c, $community), 'type'));
    }

    public function testBlockedActorNeitherCreatesNorBumpsChannelActivity(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        [$community, $channel] = $this->communityWith('bn3', $a, $b, $c);
        $this->setLevelAll($a, $channel);
        $this->block($a, $b);

        $this->send($b, 'bn3', 'first from blocked');
        $beforeC = $this->channelNotifs($a, $community);
        self::assertSame([], $beforeC);

        $this->send($c, 'bn3', 'from c');
        $afterC = $this->channelNotifs($a, $community);
        self::assertSame([['type' => 'channel_activity', 'messageCount' => 1, 'actorIds' => [(int) $c->getId()]]], $afterC);

        $this->send($b, 'bn3', 'second from blocked');
        self::assertSame($afterC, $this->channelNotifs($a, $community));
    }

    public function testSoleActorChannelActivityHiddenAfterBlock(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        [, $channel] = $this->communityWith('bn9', $a, $b);
        $this->setLevelAll($a, $channel);
        $this->send($b, 'bn9', 'one');
        $this->send($b, 'bn9', 'two');
        self::assertCount(1, $this->visibleNotifs($a, 'bn9'));

        $this->block($a, $b);

        self::assertSame([], $this->visibleNotifs($a, 'bn9'));
    }

    public function testMixedChannelActivityStaysAfterBlockingOneActor(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        [, $channel] = $this->communityWith('bn10', $a, $b, $c);
        $this->setLevelAll($a, $channel);
        $this->send($b, 'bn10', 'from b');
        $this->send($c, 'bn10', 'from c');

        $this->block($a, $b);

        self::assertCount(1, $this->visibleNotifs($a, 'bn10'));
    }

    public function testNoDmNotificationFromBlockedUserInGroup(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        $this->communityWith('bn4', $a, $b, $c);
        $conversation = $this->groupConversation($a, $b, $c);
        $this->block($a, $b);

        $this->sendDm($b, $conversation, 'hello group');

        self::assertSame(0, $this->dmNotifCount($a));
        self::assertSame(1, $this->dmNotifCount($c));
    }

    public function testNoDmNotificationForThreadReplyFromBlockedUser(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        $this->communityWith('bn5', $a, $b, $c);
        $conversation = $this->groupConversation($a, $b, $c);
        $this->block($a, $b);

        $root = $this->sendDm($a, $conversation, 'root');
        $rootId = basename((string) $root['@id']);

        $this->jsonClient($b)->request('POST', '/api/v1/messages/'.$rootId.'/replies', ['json' => ['text' => 'reply']]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(0, $this->dmNotifCount($a));
        self::assertSame(2, $this->dmNotifCount($c));
    }

    public function testModeratorActorStillNotifies(): void
    {
        $a = UserFactory::createOne();
        $m = UserFactory::createOne();
        [$community] = $this->communityWith('bn6', $a, $m);
        $this->block($a, $m);

        $repo = static::getContainer()->get(CommunityMemberRepository::class);
        \assert($repo instanceof CommunityMemberRepository);
        $member = $repo->findOneBy(['user' => $m, 'community' => $community]);
        \assert(null !== $member);
        $member->setRole(CommunityRole::Moderator);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->flush();

        $this->send($m, 'bn6', 'hey '.$this->mention($a));

        self::assertSame(['mention'], array_column($this->channelNotifs($a, $community), 'type'));
    }

    public function testModerationNotificationsUnaffected(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $admin = UserFactory::createOne();
        [$community] = $this->communityWith('bn7', $a, $b);
        CommunityMemberFactory::createAdminForCommunity($admin, $community);
        $this->block($a, $b);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/bn7/moderation', ['json' => [
            'targetUserId' => $a->getId(),
            'type' => 'warn',
            'reason' => 'Spam',
        ]]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame(['warn'], array_column($this->channelNotifs($a, $community), 'type'));
    }

    public function testFilterRecipientsWorksWithoutSecurityToken(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        [$community, $channel] = $this->communityWith('bn8', $a, $b, $c);
        $this->block($a, $b);

        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()
            ->inPage($page)
            ->byUser($b)
            ->withText('hi '.$this->mention($a).' '.$this->mention($c))
            ->with(['mentionedUserIds' => [(int) $a->getId(), (int) $c->getId()]])
            ->create();

        $tokenStorage = static::getContainer()->get(TokenStorageInterface::class);
        \assert($tokenStorage instanceof TokenStorageInterface);
        $tokenStorage->setToken(null);

        $dispatcher = static::getContainer()->get(MessageNotificationDispatcherInterface::class);
        \assert($dispatcher instanceof MessageNotificationDispatcherInterface);
        $dispatcher->dispatchFor($message);

        self::assertSame([], $this->channelNotifs($a, $community));
        self::assertSame(['mention'], array_column($this->channelNotifs($c, $community), 'type'));
    }
}
