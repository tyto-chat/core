<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Functional\Conversation\ConversationPolicyFixturesTrait;
use App\Utils\Topics;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class EncryptedThreadTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use EnvelopeFixturesTrait;
    use Factories;
    use ResetDatabase;

    public function testReplyRequiresEnvelope(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $root = $this->send($a, $conversation);

        $this->reply($b, $root, ['text' => 'plain reply']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->root($root)->getReplyCount());
    }

    public function testReplyAcceptedWithEnvelopeAndPlaintextParent(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $root = $this->send($a, $conversation);
        $envelope = self::envelope(ciphertext: 'cmVwbHk');
        $id = (string) Uuid::v4();

        $reply = $this->reply($b, $root, ['text' => $envelope, 'id' => $id]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($envelope, $reply['text']);
        self::assertSame('/api/v1/messages/'.$id, $reply['@id']);
        self::assertSame($root['@id'], $reply['parent']['@id']);
    }

    public function testEnvelopeReplyRejectedInPlainConversation(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $conversation = $this->conversationBetween([$a, $b]);
        $root = $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => 'plain root'],
        ])->toArray();

        $this->reply($b, $root, ['text' => self::envelope()]);
        self::assertResponseStatusCodeSame(422);

        $this->reply($b, $root, ['text' => 'plain reply', 'id' => (string) Uuid::v4()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReplyIncrementsReplyCountAndLastReplyAt(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $root = $this->send($a, $conversation);

        $this->reply($b, $root, ['text' => self::envelope()]);
        $this->reply($a, $root, ['text' => self::envelope()]);

        $reloaded = $this->root($root);
        self::assertSame(2, $reloaded->getReplyCount());
        self::assertNotNull($reloaded->getLastReplyAt());
    }

    public function testReplyPublishedOnThreadTopic(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $root = $this->send($a, $conversation);

        $reply = $this->root($this->reply($b, $root, ['text' => self::envelope()]));

        self::assertSame(Topics::conversationThreads((int) $conversation->getId()), $reply->getPublishTopic());
    }

    public function testThreadKeysetPagingUnchangedForEncrypted(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $root = $this->send($a, $conversation);
        $uuids = [(string) Uuid::v4(), (string) Uuid::v4(), (string) Uuid::v4()];
        sort($uuids);
        $ids = [];
        foreach ($uuids as $i => $uuid) {
            $ids[] = (string) $this->reply($b, $root, ['text' => self::envelope(ciphertext: 'cg'.$i), 'id' => $uuid])['@id'];
        }

        $page = $this->jsonClient($a)->request('GET', $root['@id'].'/thread?limit=2&before='.basename($ids[2]))->toArray();
        $items = $page['member'] ?? $page['hydra:member'];

        self::assertSame([$ids[0], $ids[1]], array_column($items, '@id'));
    }

    /** @return array<string, mixed> */
    private function send(User $as, Conversation $conversation): array
    {
        $body = $this->jsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => self::envelope()],
        ])->toArray(false);
        self::assertResponseStatusCodeSame(201);

        return $body;
    }

    /**
     * @param array<string, mixed> $root
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function reply(User $as, array $root, array $json): array
    {
        return $this->jsonClient($as)->request('POST', $root['@id'].'/replies', ['json' => $json])->toArray(false);
    }

    /** @param array<string, mixed> $root */
    private function root(array $root): Message
    {
        $this->em()->clear();
        $message = $this->em()->find(Message::class, basename((string) $root['@id']));
        self::assertNotNull($message);

        return $message;
    }
}
