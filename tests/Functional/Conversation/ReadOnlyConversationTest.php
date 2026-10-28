<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Entity\Conversation;
use App\Entity\User;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ReadOnlyConversationTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use Factories;
    use ResetDatabase;

    private const MESSAGE = "You can't send messages in this conversation.";

    public function testPlainConversationReadOnlyWhenRequired(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->storeMode('required');

        foreach ([$a, $b] as $user) {
            $body = $this->detail($user, $conversation);
            self::assertTrue($body['readOnly']);
            self::assertSame('operator_required', $body['readOnlyReason']);
        }
    }

    public function testPlainGroupReadOnlyWhenRequired(): void
    {
        [[$a], $conversation] = $this->conversationOf(3);
        $this->storeMode('required');

        self::assertSame('operator_required', $this->detail($a, $conversation)['readOnlyReason']);
        $this->assertReadOnlyError($this->send($a, $conversation));
    }

    public function testPlainConversationReadOnlyWhenMemberRefuses(): void
    {
        [[$a, $b, $c], $group] = $this->conversationOf(3);
        $pair = $this->conversationBetween([$a, $b]);
        $this->refuses($c);

        self::assertSame('participant_refuses_plain', $this->detail($a, $group)['readOnlyReason']);
        self::assertSame('participant_refuses_plain', $this->detail($c, $group)['readOnlyReason']);
        self::assertFalse($this->detail($a, $pair)['readOnly']);

        $list = array_column($this->jsonClient($a)->request('GET', '/api/v1/conversations')->toArray()['hydra:member'], null, 'identifier');
        self::assertSame('participant_refuses_plain', $list[$group->getIdentifier()]['readOnlyReason']);
        self::assertFalse($list[$pair->getIdentifier()]['readOnly']);
    }

    public function testListCarriesOperatorRequired(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $this->storeMode('required');

        $list = array_column($this->jsonClient($a)->request('GET', '/api/v1/conversations')->toArray()['hydra:member'], null, 'identifier');

        self::assertTrue($list[$conversation->getIdentifier()]['readOnly']);
        self::assertSame('operator_required', $list[$conversation->getIdentifier()]['readOnlyReason']);
    }

    public function testEncryptedConversationNeverReadOnly(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $encrypted = $this->conversationBetween([$a, $b], encrypted: true);
        $this->refuses($b);
        $this->storeMode('required');

        $body = $this->detail($a, $encrypted);
        self::assertFalse($body['readOnly']);
        self::assertNull($body['readOnlyReason'] ?? null);
        $list = array_column($this->jsonClient($a)->request('GET', '/api/v1/conversations')->toArray()['hydra:member'], null, 'identifier');
        self::assertFalse($list[$encrypted->getIdentifier()]['readOnly']);
    }

    public function testEncryptedConversationStillReadOnlyWhenBlocked(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $encrypted = $this->conversationBetween([$a, $b], encrypted: true);
        $this->plainJsonClient($a)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]]);
        self::assertResponseStatusCodeSame(201);

        self::assertSame('blocked', $this->detail($a, $encrypted)['readOnlyReason']);
        self::assertSame('unavailable', $this->detail($b, $encrypted)['readOnlyReason']);
    }

    public function testSendRejectedWhenReadOnly(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->refuses($b);

        $this->assertReadOnlyError($this->send($a, $conversation));
        $this->assertReadOnlyError($this->send($b, $conversation));
    }

    public function testReplyRejectedWhenReadOnly(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $root = $this->send($a, $conversation);
        $this->storeMode('required');

        $body = $this->jsonClient($b)->request('POST', $root['@id'].'/replies', ['json' => ['text' => 'reply']])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testEditRejectedWhenReadOnly(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $created = $this->send($a, $conversation);
        $this->storeMode('required');

        $body = $this->jsonClient($a)->request('PATCH', $created['@id'], [
            'json' => ['text' => 'edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testReactionAddRejectedWhenReadOnly(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $created = $this->send($a, $conversation);
        $this->refuses($a);

        $body = $this->jsonClient($b)->request('POST', $created['@id'].'/reactions', ['json' => ['emoji' => '👍']])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testOwnReactionRemovalAllowedWhenReadOnly(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $created = $this->send($a, $conversation);
        $reaction = $this->jsonClient($b)->request('POST', $created['@id'].'/reactions', ['json' => ['emoji' => '👍']])->toArray();
        $this->storeMode('required');

        $this->jsonClient($b)->request('DELETE', $reaction['@id']);

        self::assertResponseStatusCodeSame(204);
    }

    public function testAttachmentUploadRejectedWhenReadOnly(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $this->storeMode('required');

        $body = $this->uploadClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments', [
            'extra' => ['files' => ['file' => $this->uploadFile()]],
        ])->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testTypingRejectedWhenReadOnly(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $this->storeMode('required');

        $body = $this->plainJsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/typing')->toArray(false);

        $this->assertReadOnlyError($body);
    }

    public function testOwnMessageDeleteAllowedWhenReadOnly(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $created = $this->send($a, $conversation);
        $this->storeMode('required');

        $this->jsonClient($a)->request('DELETE', $created['@id']);

        self::assertResponseStatusCodeSame(204);
    }

    public function testOwnAttachmentDeleteAllowedWhenReadOnly(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $attachment = $this->uploadClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments', [
            'extra' => ['files' => ['file' => $this->uploadFile()]],
        ])->toArray();
        self::assertResponseStatusCodeSame(201);
        $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => 'with file', 'attachmentIris' => [$attachment['@id']]],
        ]);
        self::assertResponseStatusCodeSame(201);
        $this->storeMode('required');

        $this->jsonClient($a)->request('DELETE', '/api/v1/attachments/'.basename($attachment['@id']));

        self::assertResponseStatusCodeSame(204);
    }

    public function testMuteAndMarkReadAllowedWhenReadOnly(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $this->storeMode('required');

        $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mute', ['json' => ['mutedUntil' => null]]);
        self::assertResponseIsSuccessful();
        $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/mark-read');
        self::assertResponseIsSuccessful();
    }

    public function testHistoryStillReadableWhenReadOnly(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->send($a, $conversation, 'before the switch');
        $this->storeMode('required');

        $page = $this->jsonClient($b)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages/current')->toArray();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['before the switch'], array_column($page['messages'], 'text'));
    }

    public function testReadOnlyLiftedWhenModeBackToEnabled(): void
    {
        [[$a], $conversation] = $this->conversationOf(2);
        $this->storeMode('required');
        $this->assertReadOnlyError($this->send($a, $conversation));

        $this->storeMode('enabled');

        $this->send($a, $conversation);
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($this->detail($a, $conversation)['readOnly']);
    }

    public function testReadOnlyLiftedWhenRefusalTurnedOff(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->refuses($b);
        $this->assertReadOnlyError($this->send($a, $conversation));

        $this->plainJsonClient($b)->request('PATCH', '/api/v1/me/preferences', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['refusePlainDms' => false],
        ]);
        self::assertResponseIsSuccessful();

        $this->send($a, $conversation);
        self::assertResponseStatusCodeSame(201);
    }

    public function testE2eeReasonsWinOverBlockReasonsForBothMembers(): void
    {
        [[$a, $b], $conversation] = $this->conversationOf(2);
        $this->plainJsonClient($a)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $b->getId()]]);
        self::assertResponseStatusCodeSame(201);
        $this->refuses($a);

        self::assertSame('participant_refuses_plain', $this->detail($a, $conversation)['readOnlyReason']);
        self::assertSame('participant_refuses_plain', $this->detail($b, $conversation)['readOnlyReason']);

        $this->storeMode('required');

        self::assertSame('operator_required', $this->detail($a, $conversation)['readOnlyReason']);
        self::assertSame('operator_required', $this->detail($b, $conversation)['readOnlyReason']);
    }

    /** @return array{0: list<User>, 1: Conversation} */
    private function conversationOf(int $count): array
    {
        $users = $this->usersSharingCommunity($count);

        return [$users, $this->conversationBetween($users)];
    }

    /** @param list<User> $users */
    private function conversationBetween(array $users, bool $encrypted = false): Conversation
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $users);
        $conversation = ConversationFactory::new(['encrypted' => $encrypted])
            ->with(['participantsHash' => Conversation::hashParticipants($ids, $encrypted), 'lastMessageAt' => new \DateTimeImmutable()])
            ->create();
        foreach ($users as $user) {
            ConversationMemberFactory::createForUserAndConversation($user, $conversation);
        }

        return $conversation;
    }

    /** @return array<string, mixed> */
    private function send(User $as, Conversation $conversation, string $text = 'hi'): array
    {
        return $this->jsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => $text],
        ])->toArray(false);
    }

    /** @return array<string, mixed> */
    private function detail(User $as, Conversation $conversation): array
    {
        $body = $this->jsonClient($as)->request('GET', '/api/v1/conversations/'.$conversation->getIdentifier())->toArray();
        self::assertResponseIsSuccessful();

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function assertReadOnlyError(array $body): void
    {
        self::assertResponseStatusCodeSame(422);
        self::assertSame(self::MESSAGE, $body['error']);
    }

    private function uploadFile(): UploadedFile
    {
        $path = sys_get_temp_dir().'/dm-e2ee-ro-'.uniqid().'.txt';
        file_put_contents($path, 'hello');

        return new UploadedFile($path, 'note.txt', 'text/plain', null, true);
    }
}
