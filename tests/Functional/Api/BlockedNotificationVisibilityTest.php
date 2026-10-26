<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Community;
use App\Entity\Conversation;
use App\Entity\User;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class BlockedNotificationVisibilityTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function communityWith(string $identifier, User ...$users): Community
    {
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        foreach ($users as $user) {
            CommunityMemberFactory::createForUserAndCommunity($user, $community);
        }
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        return $community;
    }

    private function block(User $blocker, User $target): void
    {
        $this->plainJsonClient($blocker)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $target->getId()]]);
        self::assertResponseStatusCodeSame(201);
    }

    private function unblock(User $blocker, User $target): void
    {
        $this->plainJsonClient($blocker)->request('DELETE', '/api/v1/me/blocks/'.$target->getId());
        self::assertResponseStatusCodeSame(204);
    }

    private function mention(User $author, string $community, User $target): void
    {
        $this->jsonClient($author)->request(
            'POST',
            "/api/v1/communities/{$community}/channels/general/messages",
            ['json' => ['text' => "hi [@u](user:{$target->getId()})"]],
        );
        self::assertResponseIsSuccessful();
    }

    /** @return list<array<string, mixed>> */
    private function communityNotifs(User $user, string $community): array
    {
        $data = $this->jsonClient($user)->request('GET', "/api/v1/communities/{$community}/notifications")->toArray();
        self::assertResponseIsSuccessful();

        return $data['hydra:member'];
    }

    /** @return list<array<string, mixed>> */
    private function dmNotifs(User $user): array
    {
        $data = $this->jsonClient($user)->request('GET', '/api/v1/me/notifications')->toArray();
        self::assertResponseIsSuccessful();

        return $data['hydra:member'];
    }

    /** @return array<string, int> */
    private function unreadCounts(User $user): array
    {
        $data = $this->jsonClient($user)->request('GET', '/api/v1/notifications/unread-counts')->toArray();
        self::assertResponseIsSuccessful();

        return $data['counts'];
    }

    private function groupConversation(User ...$users): Conversation
    {
        $conversation = ConversationFactory::new()->withParticipants($users)->create();
        foreach ($users as $user) {
            ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        }

        return $conversation;
    }

    public function testExistingMentionHiddenAfterBlockAndBackAfterUnblock(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $this->communityWith('bv1', $a, $b);
        $this->mention($b, 'bv1', $a);

        $before = $this->communityNotifs($a, 'bv1');
        self::assertCount(1, $before);
        self::assertArrayNotHasKey('actor', $before[0]);

        $this->block($a, $b);
        self::assertCount(0, $this->communityNotifs($a, 'bv1'));

        $this->unblock($a, $b);
        self::assertCount(1, $this->communityNotifs($a, 'bv1'));
    }

    public function testUnreadCountExcludesHiddenRows(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        $community = $this->communityWith('bv2', $a, $b, $c);
        $this->mention($b, 'bv2', $a);
        $this->mention($c, 'bv2', $a);
        $key = (string) $community->getId();

        self::assertSame(2, $this->unreadCounts($a)[$key] ?? 0);

        $this->block($a, $b);
        self::assertSame(1, $this->unreadCounts($a)[$key] ?? 0);
    }

    public function testExistingDmNotificationHidden(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();
        $this->communityWith('bv3', $a, $b, $c);
        $conversation = $this->groupConversation($a, $b, $c);

        $this->jsonClient($b)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'hello group']],
        );
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->dmNotifs($a));
        self::assertSame(1, $this->unreadCounts($a)['dm'] ?? 0);

        $this->block($a, $b);
        self::assertCount(0, $this->dmNotifs($a));
        self::assertSame(0, $this->unreadCounts($a)['dm'] ?? 0);
    }

    public function testRowsWithoutActorStayVisible(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = $this->communityWith('bv4', $a, $b);
        NotificationFactory::new()->forRecipient($a)->inCommunity($community)->create(['authorName' => 'legacy']);

        $this->block($a, $b);

        self::assertCount(1, $this->communityNotifs($a, 'bv4'));
        self::assertSame(1, $this->unreadCounts($a)[(string) $community->getId()] ?? 0);
    }
}
