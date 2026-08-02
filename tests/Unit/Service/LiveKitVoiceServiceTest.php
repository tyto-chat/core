<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Profile;
use App\Entity\User;
use App\Exception\Channel\VoiceBackendUnavailableException;
use App\Service\Voice\LiveKitVoiceService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LiveKitVoiceServiceTest extends TestCase
{
    /** @return array<mixed> */
    private function decode(string $jwt): array
    {
        $payload = explode('.', $jwt)[1];
        $json = base64_decode(strtr($payload, '-_', '+/'), true);

        return json_decode((string) $json, true, 512, \JSON_THROW_ON_ERROR);
    }

    public function testCreateRoomTokenClaims(): void
    {
        $profile = self::createStub(Profile::class);
        $profile->method('getName')->willReturn('Alice');
        $user = self::createStub(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getProfile')->willReturn($profile);
        $channel = self::createStub(Channel::class);
        $channel->method('getId')->willReturn(42);

        $service = new LiveKitVoiceService('devkey', 'devsecret_long_enough_for_hmac_hs256', 'wss://public', 'http://internal', new NullLogger());
        $claims = $this->decode($service->createRoomToken($user, $channel));

        self::assertSame('user-7', $claims['sub']);
        self::assertSame('Alice', $claims['name']);
        self::assertSame('channel-42', $claims['video']['room']);
        self::assertTrue($claims['video']['roomJoin']);
        self::assertSame(
            ['microphone', 'camera', 'screen_share', 'screen_share_audio'],
            $claims['video']['canPublishSources'],
        );
        self::assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function testListActiveRoomsThrowsOnBackendFailure(): void
    {
        $service = new LiveKitVoiceService('k', 's', 'wss://public', 'http://127.0.0.1:1', new NullLogger());

        $this->expectException(VoiceBackendUnavailableException::class);
        $service->listActiveRooms();
    }
}
