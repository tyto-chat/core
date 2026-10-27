<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Conversation;
use App\Entity\User;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\RecordingMercureHub;
use App\Utils\Topics;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ConversationPolicyEventTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{0: list<User>, 1: Conversation} */
    private function conversationOf(int $count): array
    {
        $community = CommunityFactory::createOne();
        $users = [];
        for ($i = 0; $i < $count; ++$i) {
            $user = UserFactory::createOne();
            CommunityMemberFactory::createForUserAndCommunity($user, $community);
            $users[] = $user;
        }
        $conversation = ConversationFactory::new()->withParticipants($users)->create();
        foreach ($users as $user) {
            ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        }

        return [$users, $conversation];
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

    /** @return list<array{topics: list<string>, data: array<string, mixed>}> */
    private function policyUpdates(): array
    {
        $found = [];
        foreach (RecordingMercureHub::updates() as $update) {
            $data = json_decode($update->getData(), true);
            if (is_array($data) && 'conversation.policy' === ($data['type'] ?? null)) {
                $found[] = ['topics' => array_values($update->getTopics()), 'data' => $data];
            }
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    private function policyFor(int $userId): ?array
    {
        foreach ($this->policyUpdates() as $update) {
            if ([Topics::userConversationActivity($userId)] === $update['topics']) {
                return $update['data'];
            }
        }

        return null;
    }

    public function testBlockPublishesPerMemberPolicyEvents(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        RecordingMercureHub::reset();

        $this->block($a, $b);

        self::assertCount(2, $this->policyUpdates());
        self::assertSame(
            ['type' => 'conversation.policy', 'conversationIdentifier' => $conversation->getIdentifier(), 'readOnly' => true, 'readOnlyReason' => 'blocked'],
            $this->policyFor((int) $a->getId()),
        );
        self::assertSame(
            ['type' => 'conversation.policy', 'conversationIdentifier' => $conversation->getIdentifier(), 'readOnly' => true, 'readOnlyReason' => 'unavailable'],
            $this->policyFor((int) $b->getId()),
        );
    }

    public function testPolicyEventNeverOnConversationTopic(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        RecordingMercureHub::reset();

        $this->block($a, $b);

        foreach ($this->policyUpdates() as $update) {
            self::assertNotContains($conversation->getTopic(), $update['topics']);
            self::assertNotContains(Topics::conversation((int) $conversation->getId()), $update['topics']);
        }
    }

    public function testUnblockPublishesWritable(): void
    {
        [[$a, $b]] = $this->conversationOf(2);
        $this->block($a, $b);
        RecordingMercureHub::reset();

        $this->unblock($a, $b);

        foreach ([$a, $b] as $user) {
            $data = $this->policyFor((int) $user->getId());
            self::assertNotNull($data);
            self::assertFalse($data['readOnly']);
            self::assertNull($data['readOnlyReason']);
        }
    }

    public function testMutualBlockSendsNoEventToMemberWhoseStateIsUnchanged(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->block($b, $a);
        RecordingMercureHub::reset();

        $this->block($a, $b);

        self::assertCount(1, $this->policyUpdates());
        self::assertNull($this->policyFor((int) $b->getId()));
        self::assertSame(
            ['type' => 'conversation.policy', 'conversationIdentifier' => $conversation->getIdentifier(), 'readOnly' => true, 'readOnlyReason' => 'blocked'],
            $this->policyFor((int) $a->getId()),
        );
    }

    public function testUnblockWhileOtherSideStillBlocksOnlyNotifiesTheUnblocker(): void
    {
        [[$a, $b]] = $this->conversationOf(2);
        $this->block($a, $b);
        $this->block($b, $a);
        RecordingMercureHub::reset();

        $this->unblock($a, $b);

        self::assertCount(1, $this->policyUpdates());
        self::assertNull($this->policyFor((int) $b->getId()));
        self::assertSame('unavailable', $this->policyFor((int) $a->getId())['readOnlyReason'] ?? null);
    }

    public function testRepeatedBlockPublishesNoPolicyEvents(): void
    {
        [[$a, $b]] = $this->conversationOf(2);
        $this->block($a, $b);
        RecordingMercureHub::reset();

        $this->block($a, $b);

        self::assertSame([], $this->policyUpdates());
    }

    public function testNoEventWithoutExistingConversation(): void
    {
        $community = CommunityFactory::createOne();
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);
        RecordingMercureHub::reset();

        $this->block($a, $b);

        self::assertSame([], $this->policyUpdates());
    }

    public function testGroupConversationsGetNoPolicyEvent(): void
    {
        [[$a, $b]] = $this->conversationOf(3);
        RecordingMercureHub::reset();

        $this->block($a, $b);

        self::assertSame([], $this->policyUpdates());
    }
}
