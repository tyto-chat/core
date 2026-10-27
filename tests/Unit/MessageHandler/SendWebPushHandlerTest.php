<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Async\Handler\SendWebPushHandler;
use App\Async\SendWebPushMessage;
use App\Entity\PushSubscription;
use App\Repository\PushSubscriptionRepository;
use App\Service\Notification\WebPushSenderInterface;
use App\Service\User\UserBlockServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class SendWebPushHandlerTest extends TestCase
{
    private function settings(): \App\Service\Settings\SettingsServiceInterface
    {
        $settings = $this->createMock(\App\Service\Settings\SettingsServiceInterface::class);
        $settings->method('get')->willReturn('tyto.chat');

        return $settings;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('body');

        return $translator;
    }

    public function testNoOpWhenSenderNotConfigured(): void
    {
        $sender = $this->createMock(WebPushSenderInterface::class);
        $sender->method('isConfigured')->willReturn(false);
        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->expects(self::never())->method('findByUserId');

        $handler = new SendWebPushHandler($repo, $sender, $this->translator(), $this->settings(), new \Psr\Log\NullLogger(), $this->blocks());
        $handler(new SendWebPushMessage(1, 'notification.push.mention', [], '/m/x', 'tag'));
    }

    public function testSendsToEverySubscription(): void
    {
        $subs = [self::subscription('https://push.example/a'), self::subscription('https://push.example/b')];
        $sender = $this->createMock(WebPushSenderInterface::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::exactly(2))->method('send')->willReturn(true);

        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->method('findByUserId')->willReturn($subs);
        $repo->expects(self::once())->method('deleteByEndpoints')->with([]);

        $handler = new SendWebPushHandler($repo, $sender, $this->translator(), $this->settings(), new \Psr\Log\NullLogger(), $this->blocks());
        $handler(new SendWebPushMessage(7, 'notification.push.mention', [], '/m/x', 'tag'));
    }

    public function testPrunesDeadSubscription(): void
    {
        $alive = self::subscription('https://push.example/alive');
        $dead = self::subscription('https://push.example/dead');
        $sender = $this->createMock(WebPushSenderInterface::class);
        $sender->method('isConfigured')->willReturn(true);
        // First subscription alive, second gone (404/410).
        $sender->method('send')->willReturnCallback(static fn (PushSubscription $s): bool => $s === $alive);

        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->method('findByUserId')->willReturn([$alive, $dead]);
        $repo->expects(self::once())->method('deleteByEndpoints')->with(['https://push.example/dead']);

        $handler = new SendWebPushHandler($repo, $sender, $this->translator(), $this->settings(), new \Psr\Log\NullLogger(), $this->blocks());
        $handler(new SendWebPushMessage(7, 'notification.push.mention', [], '/m/x', 'tag'));
    }

    public function testSkipsWhenRecipientBlockedTheActorAfterQueueing(): void
    {
        $sender = $this->createMock(WebPushSenderInterface::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::never())->method('send');
        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->expects(self::never())->method('findByUserId');

        $handler = new SendWebPushHandler($repo, $sender, $this->translator(), $this->settings(), new \Psr\Log\NullLogger(), $this->blocks([7 => [3]]));
        $handler(new SendWebPushMessage(7, 'notification.push.dm_message', [], '/m/x', 'tag', 3));
    }

    public function testSendsWhenActorIsNotBlocked(): void
    {
        $sender = $this->createMock(WebPushSenderInterface::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::once())->method('send')->willReturn(true);
        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->method('findByUserId')->willReturn([self::subscription('https://push.example/a')]);

        $handler = new SendWebPushHandler($repo, $sender, $this->translator(), $this->settings(), new \Psr\Log\NullLogger(), $this->blocks([7 => [4]]));
        $handler(new SendWebPushMessage(7, 'notification.push.dm_message', [], '/m/x', 'tag', 3));
    }

    public function testMessageQueuedBeforeActorFieldExistedStillSends(): void
    {
        $sender = $this->createMock(WebPushSenderInterface::class);
        $sender->method('isConfigured')->willReturn(true);
        $sender->expects(self::once())->method('send')->willReturn(true);
        $repo = $this->createMock(PushSubscriptionRepository::class);
        $repo->method('findByUserId')->willReturn([self::subscription('https://push.example/a')]);
        $current = serialize(new SendWebPushMessage(7, 'notification.push.mention', [], '/m/x', 'tag'));
        $legacy = unserialize(str_replace([':6:{', 's:11:"actorUserId";N;'], [':5:{', ''], $current));
        self::assertInstanceOf(SendWebPushMessage::class, $legacy);

        $handler = new SendWebPushHandler($repo, $sender, $this->translator(), $this->settings(), new \Psr\Log\NullLogger(), $this->blocks([7 => [3]]));
        $handler($legacy);
    }

    /** @param array<int, list<int>> $blockedByRecipient */
    private function blocks(array $blockedByRecipient = []): UserBlockServiceInterface
    {
        $blocks = $this->createMock(UserBlockServiceInterface::class);
        $blocks->method('enforcedBlockedIdsByBlocker')->willReturn($blockedByRecipient);

        return $blocks;
    }

    private static function subscription(string $endpoint): PushSubscription
    {
        $subscription = new PushSubscription();
        $subscription->setEndpoint($endpoint);

        return $subscription;
    }
}
