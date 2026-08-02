<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Conversation;
use App\Entity\User;
use App\Enum\Notification\NotificationType;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\ConversationFactory;
use App\Tests\Factory\ConversationMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ConversationMessageTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, User, Conversation} */
    private function setupConversation(string $ident = 'dm-c'): array
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($ident)->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);

        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        ConversationMemberFactory::createForUserAndConversation($b, $conversation);

        return [$a, $b, $conversation];
    }

    public function testMemberCanSendMessage(): void
    {
        [, $b, $conversation] = $this->setupConversation();

        $response = $this->jsonClient($b)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'hi there']],
        );

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['text' => 'hi there']);

        $data = $response->toArray();
        self::assertArrayHasKey('createdBy', $data);
        self::assertIsArray($data['createdBy']);
        self::assertArrayHasKey('profile', $data['createdBy']);
    }

    public function testNonMemberCannotSendMessage(): void
    {
        $outsider = UserFactory::createOne();
        [, , $conversation] = $this->setupConversation('dm-deny');

        $this->jsonClient($outsider)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'sneaky']],
        );

        self::assertResponseStatusCodeSame(403);
    }

    /** A conversation with no messages yet has no page row — see the channel twin. */
    public function testCurrentPageOnAFreshConversationReturnsAnEmptyPage(): void
    {
        [$a, , $conversation] = $this->setupConversation('dm-empty');

        $response = $this->jsonClient($a)->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages/current',
        );

        self::assertResponseIsSuccessful();
        self::assertSame([], $response->toArray()['messages']);
    }

    public function testCurrentPageReturnsSentMessages(): void
    {
        [$a, $b, $conversation] = $this->setupConversation('dm-list');

        $this->jsonClient($a)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'one']],
        );
        $this->jsonClient($b)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'two']],
        );

        $response = $this->jsonClient($a)->request(
            'GET',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages/current',
        );

        self::assertResponseIsSuccessful();
        $data = $response->toArray();
        self::assertArrayHasKey('messages', $data);
        self::assertCount(2, $data['messages']);
        self::assertEqualsCanonicalizing(['one', 'two'], array_column($data['messages'], 'text'));
    }

    public function testAnonymousCannotSendMessage(): void
    {
        [, , $conversation] = $this->setupConversation('dm-anon');

        $this->jsonClient()->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'nope']],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testMemberCanUploadAttachment(): void
    {
        [$a, , $conversation] = $this->setupConversation('dm-att');

        $filePath = sys_get_temp_dir().'/dm-attach-'.uniqid().'.txt';
        file_put_contents($filePath, 'hello');
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($filePath, 'note.txt', 'text/plain', null, true);

        $this->jsonClient($a)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments',
            [
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'extra' => ['files' => ['file' => $file]],
            ],
        );

        self::assertResponseStatusCodeSame(201);
    }

    public function testSendingMessageBumpsConversationLastMessageAt(): void
    {
        [$a, , $conversation] = $this->setupConversation('dm-bump');

        self::assertNull($conversation->getLastMessageAt());

        $this->jsonClient($a)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'tick']],
        );
        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->refresh($conversation);
        self::assertNotNull($conversation->getLastMessageAt());
    }

    public function testSendingMessageCreatesDmNotificationForRecipient(): void
    {
        [$a, $b, $conversation] = $this->setupConversation('dm-notif');

        $this->jsonClient($a)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'hi']],
        );
        self::assertResponseStatusCodeSame(201);

        $notifs = static::getContainer()->get('doctrine.orm.entity_manager')
            ->getRepository(\App\Entity\Notification::class)
            ->findBy(['recipient' => $b]);

        self::assertCount(1, $notifs);
        self::assertSame(NotificationType::DmMessage, $notifs[0]->getType());
        self::assertSame($conversation->getIdentifier(), $notifs[0]->getConversationIdentifier());
        self::assertNull($notifs[0]->getCommunity());
    }

    public function testMutedRecipientReceivesNoDmNotification(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('dm-muted')->create();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        CommunityMemberFactory::createForUserAndCommunity($b, $community);

        $conversation = ConversationFactory::new()->withParticipants([$a, $b])->create();
        ConversationMemberFactory::createForUserAndConversation($a, $conversation);
        $muted = ConversationMemberFactory::createForUserAndConversation($b, $conversation);
        $muted->setMutedUntil(new \DateTimeImmutable('+1 hour'));
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->flush();

        $this->jsonClient($a)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/messages',
            ['json' => ['text' => 'whisper']],
        );
        self::assertResponseStatusCodeSame(201);

        $notifs = $em->getRepository(\App\Entity\Notification::class)->findBy(['recipient' => $b]);
        self::assertCount(0, $notifs, 'muted recipient should not receive dm_message notification');
    }

    public function testNonMemberCannotUploadAttachment(): void
    {
        $outsider = UserFactory::createOne();
        [, , $conversation] = $this->setupConversation('dm-att-deny');

        $filePath = sys_get_temp_dir().'/dm-attach-deny-'.uniqid().'.txt';
        file_put_contents($filePath, 'sneaky');
        $file = new \Symfony\Component\HttpFoundation\File\UploadedFile($filePath, 'note.txt', 'text/plain', null, true);

        $this->jsonClient($outsider)->request(
            'POST',
            '/api/v1/conversations/'.$conversation->getIdentifier().'/attachments',
            [
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'extra' => ['files' => ['file' => $file]],
            ],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAuthorCannotEditDmMessage(): void
    {
        [$a, $b, $conversation] = $this->setupConversation('dm-edit-deny');
        $created = $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => 'mine'],
        ])->toArray();

        $this->jsonClient($b)->request('PATCH', $created['@id'], [
            'json' => ['text' => 'hijacked'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNonAuthorCannotDeleteDmMessage(): void
    {
        [$a, $b, $conversation] = $this->setupConversation('dm-del-deny');
        $created = $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => 'mine'],
        ])->toArray();

        $this->jsonClient($b)->request('DELETE', $created['@id']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthorCanEditOwnDmMessage(): void
    {
        [$a, , $conversation] = $this->setupConversation('dm-edit-ok');
        $created = $this->jsonClient($a)->request('POST', '/api/v1/conversations/'.$conversation->getIdentifier().'/messages', [
            'json' => ['text' => 'mine'],
        ])->toArray();

        $this->jsonClient($a)->request('PATCH', $created['@id'], [
            'json' => ['text' => 'edited'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);

        self::assertResponseStatusCodeSame(200);
    }
}
