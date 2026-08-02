<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use App\Service\Voice\ChannelParticipantStoreInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class VoiceWebhookTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function sign(string $body): string
    {
        $key = (string) ($_ENV['LIVEKIT_API_KEY'] ?? 'test_key');
        $secret = (string) ($_ENV['LIVEKIT_API_SECRET'] ?? 'test_secret');

        return (new AccessToken($key, $secret))
            ->init((new AccessTokenOptions())->setIdentity('server'))
            ->setSha256(base64_encode(hash('sha256', $body, true)))
            ->toJwt();
    }

    public function testTamperedBodyRejected(): void
    {
        $signed = $this->sign('{"event":"participant_joined"}');
        $client = static::createClient();
        $client->request('POST', '/api/livekit/webhook', [
            'headers' => [
                'Authorization' => $signed,
                'Content-Type' => 'application/json',
            ],
            'body' => '{"event":"participant_left"}',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testValidJoinCreatesParticipant(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('vw-com')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->audio()->with(['identifier' => 'voice-1'])->create();
        $channelId = $channel->getId();

        $body = json_encode([
            'event' => 'participant_joined',
            'room' => ['name' => 'channel-'.$channelId],
            'participant' => ['identity' => 'user-'.$user->getId()],
        ], \JSON_THROW_ON_ERROR);

        $client = static::createClient();
        $client->request('POST', '/api/livekit/webhook', [
            'headers' => [
                'Authorization' => $this->sign($body),
                'Content-Type' => 'application/json',
            ],
            'body' => $body,
        ]);

        self::assertResponseIsSuccessful();

        $store = static::getContainer()->get(ChannelParticipantStoreInterface::class);
        \assert($store instanceof ChannelParticipantStoreInterface);
        self::assertNotNull($store->findOneByUserAndChannel($user, $channel));
    }

    public function testRoomFinishedClearsParticipants(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('vw-rf')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->audio()->with(['identifier' => 'voice-rf'])->create();
        $channelId = $channel->getId();

        $join = json_encode([
            'event' => 'participant_joined',
            'room' => ['name' => 'channel-'.$channelId],
            'participant' => ['identity' => 'user-'.$user->getId()],
        ], \JSON_THROW_ON_ERROR);
        $client = static::createClient();
        // The participant store is in-memory in tests; a kernel reboot would drop the join.
        $client->disableReboot();
        $client->request('POST', '/api/livekit/webhook', [
            'headers' => ['Authorization' => $this->sign($join), 'Content-Type' => 'application/json'],
            'body' => $join,
        ]);
        self::assertResponseIsSuccessful();

        $store = static::getContainer()->get(ChannelParticipantStoreInterface::class);
        \assert($store instanceof ChannelParticipantStoreInterface);
        self::assertNotNull($store->findOneByUserAndChannel($user, $channel));

        // Room closes — no participant on the event; every row for the channel drops.
        $finished = json_encode([
            'event' => 'room_finished',
            'room' => ['name' => 'channel-'.$channelId],
        ], \JSON_THROW_ON_ERROR);
        $client->request('POST', '/api/livekit/webhook', [
            'headers' => ['Authorization' => $this->sign($finished), 'Content-Type' => 'application/json'],
            'body' => $finished,
        ]);
        self::assertResponseIsSuccessful();

        self::assertNull($store->findOneByUserAndChannel($user, $channel));
    }
}
