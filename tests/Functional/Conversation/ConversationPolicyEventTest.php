<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Entity\Conversation;
use App\Entity\User;
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
    use ConversationPolicyFixturesTrait;
    use Factories;
    use ResetDatabase;

    public function testRefusalFlipPublishesPolicyEventPerPlainConversation(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $pair = $this->conversationBetween([$a, $b]);
        $group = $this->conversationBetween([$a, $b, $c]);
        RecordingMercureHub::reset();

        $this->setRefusal($a, true);

        $events = $this->policyEvents();
        self::assertSame(5, array_sum(array_map(count(...), $events)));
        foreach ([$a, $b] as $user) {
            self::assertContains($this->event($pair, true, 'participant_refuses_plain'), $events[(int) $user->getId()] ?? []);
        }
        foreach ([$a, $b, $c] as $user) {
            self::assertContains($this->event($group, true, 'participant_refuses_plain'), $events[(int) $user->getId()] ?? []);
        }
    }

    public function testRefusalTurnedOffPublishesWritable(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $pair = $this->conversationBetween([$a, $b]);
        $this->setRefusal($a, true);
        RecordingMercureHub::reset();

        $this->setRefusal($a, false);

        $events = $this->policyEvents();
        self::assertSame([$this->event($pair, false, null)], $events[(int) $a->getId()] ?? null);
        self::assertSame([$this->event($pair, false, null)], $events[(int) $b->getId()] ?? null);
    }

    public function testRefusalFlipPublishesNothingForEncryptedConversations(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->conversationBetween([$a, $b], encrypted: true);
        RecordingMercureHub::reset();

        $this->setRefusal($a, true);

        self::assertSame([], $this->policyEvents());
    }

    public function testRefusalFlipPublishesNothingWhenStateUnchanged(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->conversationBetween([$a, $b]);
        $this->refuses($b);
        RecordingMercureHub::reset();

        $this->setRefusal($a, true);

        self::assertSame([], $this->policyEvents());
    }

    public function testModeChangeDispatchesServerScopePolicyEvent(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->conversationBetween([$a, $b]);
        $this->conversationBetween([$a, $c], encrypted: true);
        $outsider = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();
        RecordingMercureHub::reset();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['encryptedDmsMode' => 'required'],
        ]);
        self::assertResponseIsSuccessful();

        $notified = [];
        foreach (RecordingMercureHub::updates() as $update) {
            $data = json_decode($update->getData(), true);
            if (is_array($data) && 'conversation.policy' === ($data['type'] ?? null)) {
                self::assertSame(['type' => 'conversation.policy', 'scope' => 'server'], $data);
                array_push($notified, ...$update->getTopics());
            }
        }
        sort($notified);
        $expected = [Topics::userConversationActivity((int) $a->getId()), Topics::userConversationActivity((int) $b->getId())];
        sort($expected);
        self::assertSame($expected, $notified);
        self::assertNotContains(Topics::userConversationActivity((int) $c->getId()), $notified);
        self::assertNotContains(Topics::userConversationActivity((int) $outsider->getId()), $notified);
    }

    public function testUnchangedModePublishesNothing(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->conversationBetween([$a, $b]);
        $admin = UserFactory::new()->admin()->create();
        RecordingMercureHub::reset();

        $this->plainJsonClient($admin)->request('PATCH', '/api/v1/admin/server-config', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['encryptedDmsMode' => 'enabled'],
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->policyEvents());
    }

    private function setRefusal(User $user, bool $on): void
    {
        $this->plainJsonClient($user)->request('PATCH', '/api/v1/me/preferences', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['refusePlainDms' => $on],
        ]);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function event(Conversation $conversation, bool $readOnly, ?string $reason): array
    {
        return ['type' => 'conversation.policy', 'conversationIdentifier' => $conversation->getIdentifier(), 'readOnly' => $readOnly, 'readOnlyReason' => $reason];
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function policyEvents(): array
    {
        $byUser = [];
        foreach (RecordingMercureHub::updates() as $update) {
            $data = json_decode($update->getData(), true);
            if (!is_array($data) || 'conversation.policy' !== ($data['type'] ?? null)) {
                continue;
            }
            foreach ($update->getTopics() as $topic) {
                if (1 === preg_match('#^u/(\d+)/d$#', $topic, $m)) {
                    $byUser[(int) $m[1]][] = $data;
                }
            }
        }

        return $byUser;
    }

    /** @param list<User> $users */
    private function conversationBetween(array $users, bool $encrypted = false): Conversation
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $users);
        $conversation = ConversationFactory::new(['encrypted' => $encrypted])
            ->with(['participantsHash' => Conversation::hashParticipants($ids, $encrypted)])
            ->create();
        foreach ($users as $user) {
            ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        }

        return $conversation;
    }
}
