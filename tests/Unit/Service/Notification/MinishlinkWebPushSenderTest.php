<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Notification;

use App\Service\Notification\MinishlinkWebPushSender;
use App\Service\Settings\SettingsServiceInterface;
use App\Service\Webhook\WebhookUrlGuard;
use GuzzleHttp\Client;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\TestCase;

final class MinishlinkWebPushSenderTest extends TestCase
{
    public function testPushClientNeverFollowsRedirects(): void
    {
        $keys = VAPID::createVapidKeys();
        $sender = new MinishlinkWebPushSender(
            $keys['publicKey'],
            $keys['privateKey'],
            'mailto:ops@example.com',
            (new \ReflectionClass(WebhookUrlGuard::class))->newInstanceWithoutConstructor(),
            self::createStub(SettingsServiceInterface::class),
        );

        $webPush = (new \ReflectionMethod($sender, 'client'))->invoke($sender);
        self::assertInstanceOf(WebPush::class, $webPush);
        $client = (new \ReflectionProperty(WebPush::class, 'client'))->getValue($webPush);
        self::assertInstanceOf(Client::class, $client);

        self::assertFalse($client->getConfig('allow_redirects'));
    }
}
