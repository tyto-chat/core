<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\NotificationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Stub\RecordingMercureHub;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * API Platform's `mercure:` annotation on Message auto-serializes the whole
 * entity (under `message:read`) and broadcasts it to every subscriber on the
 * container topic. That serialization runs synchronously inside the author's
 * own request, with the author's security token active — so `UserNormalizer`
 * would inject the self-only `user:read:self` group (which carries `email`)
 * into the embedded `createdBy`, leaking the author's email to every other
 * subscriber on the channel/conversation.
 *
 * This is the realtime sibling of MessageSerializationCanaryTest: a Mercure
 * broadcast, exactly like an HTTP-cache bucket, is a shared fan-out payload
 * that MUST be viewer-agnostic. These tests capture the actual published
 * Update bytes and assert no email rides them.
 */
class MercureBroadcastPiiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingMercureHub::reset();
    }

    /** @return array{\App\Entity\User, \App\Entity\Channel} */
    private function setupMember(string $communityIdentifier, string $channelIdentifier): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityIdentifier)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => $channelIdentifier])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);

        return [$user, $channel];
    }

    private function capturedPayloads(): string
    {
        $payloads = array_map(
            static fn ($update) => $update->getData(),
            RecordingMercureHub::updates(),
        );

        self::assertNotEmpty($payloads, 'expected at least one Mercure Update to be published');

        return implode("\n", $payloads);
    }

    public function testMessageCreateBroadcastDoesNotLeakAuthorEmail(): void
    {
        [$user] = $this->setupMember('mercure-pii-c', 'general');
        $email = $user->getEmail();
        self::assertNotEmpty($email);

        $this->jsonClient($user)->request('POST', '/api/v1/communities/mercure-pii-c/channels/general/messages', [
            'json' => ['text' => 'Hello world'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $allData = $this->capturedPayloads();
        self::assertStringNotContainsString(
            $email,
            $allData,
            'The author email must never ride the Mercure message-create broadcast.',
        );
        self::assertStringNotContainsString('"email"', $allData);
    }

    public function testMessageEditBroadcastDoesNotLeakAuthorEmail(): void
    {
        [$user, $channel] = $this->setupMember('mercure-pii-e', 'general');
        $email = $user->getEmail();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($user)->withText('original')->create();

        RecordingMercureHub::reset();

        $this->jsonClient($user)->request('PATCH', '/api/v1/messages/'.$message->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['text' => 'Edited text'],
        ]);
        self::assertResponseIsSuccessful();

        $allData = $this->capturedPayloads();
        self::assertStringNotContainsString(
            (string) $email,
            $allData,
            'The author email must never ride the Mercure message-edit broadcast.',
        );
        self::assertStringNotContainsString('"email"', $allData);
    }

    public function testNotificationsAreNeverPublishedPublicly(): void
    {
        $recipient = UserFactory::createOne();
        $notification = NotificationFactory::createOne(['recipient' => $recipient]);
        $notification->setIsRead(true);
        self::getContainer()->get('doctrine')->getManager()->flush();

        $topic = 'u/'.$recipient->getId().'/n';
        $publicOnTopic = array_filter(
            RecordingMercureHub::updates(),
            static fn ($update) => \in_array($topic, $update->getTopics(), true) && !$update->isPrivate(),
        );

        self::assertSame([], array_values($publicOnTopic));
    }

    public function testPrivateChannelDetailsAreNeverBroadcastToTheCommunity(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('mercure-pii-ch')->create();

        $response = $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Staff Salaries',
            'description' => 'Compensation planning',
            'type' => 'text',
            'isPrivate' => true,
            'community' => '/api/v1/communities/mercure-pii-ch',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $identifier = $response->toArray()['identifier'];

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/mercure-pii-ch/channels/'.$identifier, [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Board Bonuses'],
        ]);
        self::assertResponseIsSuccessful();

        $communityTopic = 'k/'.$community->getId();
        $onCommunityTopic = array_values(array_filter(
            RecordingMercureHub::updates(),
            static fn ($update) => \in_array($communityTopic, $update->getTopics(), true),
        ));
        self::assertNotEmpty($onCommunityTopic);

        $allData = $this->capturedPayloads();
        foreach (['Staff Salaries', 'Board Bonuses', 'Compensation planning', $identifier] as $secret) {
            self::assertStringNotContainsString($secret, $allData);
        }
        foreach ($onCommunityTopic as $update) {
            self::assertSame('community.structure', json_decode($update->getData(), true, 512, \JSON_THROW_ON_ERROR)['type'] ?? null);
        }
    }
}
