<?php

declare(strict_types=1);

namespace App\Tests\Functional\E2ee;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\Setting;
use App\Entity\User;
use App\Entity\WebhookDelivery;
use App\Enum\Message\MessageKind;
use App\Service\Gdpr\DataExportCollector;
use App\Service\Message\MessageEmbedPresenterInterface;
use App\Service\Message\MessageServiceInterface;
use App\Service\Notification\NotificationService;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WebhookFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Functional\Conversation\ConversationPolicyFixturesTrait;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class EncryptedMessageTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use EnvelopeFixturesTrait;
    use Factories;
    use ResetDatabase;

    public function testPlainTextRejectedInEncryptedConversation(): void
    {
        [$a, , $conversation] = $this->encryptedPair();

        $body = $this->send($a, $conversation, ['text' => 'hello in the clear']);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('error', $body);
        self::assertSame(0, $this->messageCount($conversation));
    }

    public function testMalformedEnvelopeRejectedInEncryptedConversation(): void
    {
        [$a, , $conversation] = $this->encryptedPair();

        $this->send($a, $conversation, ['text' => self::encodeEnvelope(['g' => 1, 'n' => 'a', 'c' => 'b', 'm' => 'c', 'text' => 'leak'])]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEnvelopeAcceptedAndReturnedVerbatim(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $envelope = self::envelope();

        $body = $this->send($a, $conversation, ['text' => $envelope]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($envelope, $body['text']);
        self::assertSame([], $this->reload($body)->getMentionedUserIds());
    }

    public function testEnvelopeRejectedInPlainConversation(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $conversation = $this->conversationBetween([$a, $b]);

        $this->send($a, $conversation, ['text' => self::envelope()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->messageCount($conversation));
    }

    public function testEnvelopeRejectedInChannel(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('e2ee-chan')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/e2ee-chan/channels/general/messages', [
            'json' => ['text' => self::envelope()],
        ]);
        self::assertResponseStatusCodeSame(422);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/e2ee-chan/channels/general/messages', [
            'json' => ['text' => 'hi', 'id' => (string) Uuid::v4()],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testEditRequiresEnvelope(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $created = $this->send($a, $conversation, ['text' => self::envelope()]);

        $this->edit($a, $created, 'plain edit');
        self::assertResponseStatusCodeSame(422);

        $edited = self::envelope(ciphertext: 'ZWRpdGVk');
        $body = $this->edit($a, $created, $edited);
        self::assertResponseIsSuccessful();
        self::assertSame($edited, $body['text']);
    }

    public function testRevisionCountAndRevisedAtExposedForHeaderCheck(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $created = $this->send($a, $conversation, ['text' => self::envelope()]);
        self::assertSame(1, $created['revisionCount']);
        self::assertIsString($created['revisedAt']);

        $body = $this->edit($a, $created, self::envelope(ciphertext: 'ZWRpdGVk'));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $body['revisionCount']);
        self::assertIsString($body['revisedAt']);
    }

    public function testEditAgainstStaleRevisionConflicts(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $created = $this->send($a, $conversation, ['text' => self::envelope()]);

        $this->edit($a, $created, self::envelope(ciphertext: 'b25l'), ['baseRevisionCount' => 1]);
        self::assertResponseIsSuccessful();

        $body = $this->edit($a, $created, self::envelope(ciphertext: 'dHdv'), ['baseRevisionCount' => 1]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('revision_conflict', $body['code']);
        self::assertSame(2, $body['currentRevisionCount']);
        self::assertSame(2, $this->reload($created)->getRevisionCount());
    }

    public function testEnvelopeEditRejectedInPlainConversation(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $conversation = $this->conversationBetween([$a, $b]);
        $created = $this->send($a, $conversation, ['text' => 'plain']);

        $this->edit($a, $created, self::envelope());

        self::assertResponseStatusCodeSame(422);
    }

    public function testClientIdAppliedInEncryptedConversation(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $id = (string) Uuid::v4();

        $body = $this->send($a, $conversation, ['text' => self::envelope(), 'id' => $id]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('/api/v1/messages/'.$id, $body['@id']);
    }

    public function testClientIdRejectedInPlainConversation(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $conversation = $this->conversationBetween([$a, $b]);

        $this->send($a, $conversation, ['text' => 'hi', 'id' => (string) Uuid::v4()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->messageCount($conversation));
    }

    public function testClientIdMustBeUuidV4(): void
    {
        [$a, , $conversation] = $this->encryptedPair();

        $this->send($a, $conversation, ['text' => self::envelope(), 'id' => 'not-a-uuid']);
        self::assertResponseStatusCodeSame(422);

        $this->send($a, $conversation, ['text' => self::envelope(), 'id' => (string) Uuid::v7()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testReactionAllowedAndStoredInPlainOnEncryptedMessage(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $created = $this->send($a, $conversation, ['text' => self::envelope()]);

        $this->jsonClient($b)->request('POST', $created['@id'].'/reactions', ['json' => ['emoji' => '👍']]);
        self::assertResponseStatusCodeSame(201);

        $message = $this->reload($created);
        self::assertArrayHasKey('👍', $message->getReactions());
    }

    public function testReactionShortcodeRulesUnchangedInDm(): void
    {
        [$a, $b, $encrypted] = $this->encryptedPair();
        $plain = $this->conversationBetween([$a, $b]);
        $encryptedMessage = $this->send($a, $encrypted, ['text' => self::envelope()]);
        $plainMessage = $this->send($a, $plain, ['text' => 'plain']);

        $plainStatus = $this->jsonClient($b)->request('POST', $plainMessage['@id'].'/reactions', ['json' => ['emoji' => ':party:']])->getStatusCode();
        $this->jsonClient($b)->request('POST', $encryptedMessage['@id'].'/reactions', ['json' => ['emoji' => ':party:']]);

        self::assertResponseStatusCodeSame($plainStatus);
    }

    public function testDmNotificationHasGenericBodyWithSender(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $envelope = self::envelope();
        $this->send($a, $conversation, ['text' => $envelope]);

        $notifications = $this->jsonClient($b)->request('GET', '/api/v1/me/notifications')->toArray()['hydra:member'];

        self::assertCount(1, $notifications);
        self::assertSame('dm_message', $notifications[0]['type']);
        self::assertSame($a->getProfile()->getName(), $notifications[0]['authorName']);
        self::assertStringNotContainsString(substr($envelope, 7, 12), (string) json_encode($notifications));
    }

    public function testPushBodyGeneric(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $this->send($a, $conversation, ['text' => self::envelope()]);
        $notification = $this->em()->getRepository(Notification::class)->findOneBy(['recipient' => $b->getId()]);
        self::assertNotNull($notification);

        $service = static::getContainer()->get(NotificationService::class);
        [$key, $params] = (new \ReflectionMethod($service, 'buildPushBody'))->invoke($service, $notification);

        self::assertSame('notification.push.dm_message', $key);
        self::assertSame($a->getProfile()->getName(), $params['%author%']);
        foreach ($params as $value) {
            self::assertStringNotContainsString('e2ee:', $value);
        }
    }

    public function testExportContainsCiphertextMarkedEncrypted(): void
    {
        [$a, $b, $encrypted] = $this->encryptedPair();
        $plain = $this->conversationBetween([$a, $b]);
        $envelope = self::envelope();
        $this->send($a, $encrypted, ['text' => $envelope]);
        $this->send($a, $plain, ['text' => 'plain text']);

        $collector = static::getContainer()->get(DataExportCollector::class);
        $rows = array_column($collector->messages($this->em()->find(User::class, $a->getId()), 60), null, 'conversationIdentifier');

        self::assertTrue($rows[$encrypted->getIdentifier()]['encrypted']);
        self::assertSame($envelope, $rows[$encrypted->getIdentifier()]['history'][0]['body']);
        self::assertFalse($rows[$plain->getIdentifier()]['encrypted']);
    }

    public function testMessageReportRejectedUserReportAllowed(): void
    {
        [$a, $b, $conversation] = $this->encryptedPair();
        $created = $this->send($a, $conversation, ['text' => self::envelope()]);

        $this->jsonClient($b)->request('POST', '/api/v1/reports', ['json' => [
            'messageId' => $this->reload($created)->getId(),
            'category' => 'harassment',
        ]]);
        self::assertResponseStatusCodeSame(422);

        $this->jsonClient($b)->request('POST', '/api/v1/reports', ['json' => [
            'userId' => $a->getId(),
            'category' => 'harassment',
        ]]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testSystemMessageNeverPostedToConversation(): void
    {
        $kindTakers = [];
        foreach ((new \ReflectionClass(MessageServiceInterface::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if (MessageKind::class === (string) $parameter->getType()) {
                    $kindTakers[] = $method->getName();
                }
            }
        }

        self::assertSame(['sendToChannel'], $kindTakers);
    }

    public function testEmbedUnavailableForEncryptedMessage(): void
    {
        [$a, , $conversation] = $this->encryptedPair();
        $created = $this->send($a, $conversation, ['text' => self::envelope()]);

        $presenter = static::getContainer()->get(MessageEmbedPresenterInterface::class);

        self::assertNull($presenter->present($this->reload($created)->getId()));
    }

    public function testWebhookNeverReceivesEncryptedContent(): void
    {
        $setting = (new Setting('webhookAllowInternalUrls'))->setValue(true);
        $this->em()->persist($setting);
        $this->em()->flush();
        WebhookFactory::new()->withTrigger('message.created')->with(['isActive' => true])->create();
        WebhookFactory::new()->withTrigger('message.replied')->with(['isActive' => true])->create();
        [$a, $b, $conversation] = $this->encryptedPair();

        $root = $this->send($a, $conversation, ['text' => self::envelope()]);
        $this->jsonClient($b)->request('POST', $root['@id'].'/replies', ['json' => ['text' => self::envelope()]]);
        self::assertResponseStatusCodeSame(201);

        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(WebhookDelivery::class)->findAll());
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<string, mixed>
     */
    private function send(User $as, Conversation $conversation, array $json): array
    {
        return $this->jsonClient($as)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => $json,
        ])->toArray(false);
    }

    /**
     * @param array<string, mixed> $created
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function edit(User $as, array $created, string $text, array $extra = []): array
    {
        return $this->jsonClient($as)->request('PATCH', $created['@id'], [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => $text, ...$extra],
        ])->toArray(false);
    }

    /** @param array<string, mixed> $created */
    private function reload(array $created): Message
    {
        $this->em()->clear();
        $message = $this->em()->find(Message::class, basename((string) $created['@id']));
        self::assertNotNull($message);

        return $message;
    }

    private function messageCount(Conversation $conversation): int
    {
        return (int) $this->em()->createQuery('SELECT COUNT(m.id) FROM App\Entity\Message m JOIN m.page p WHERE p.conversation = :c')
            ->setParameter('c', $conversation->getId())
            ->getSingleScalarResult();
    }
}
