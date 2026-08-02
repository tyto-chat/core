<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Service\Webhook\WebhookUrlGuard;
use PHPUnit\Framework\TestCase;

class WebhookUrlGuardTest extends TestCase
{
    public function testRejectsLoopbackAndPrivateUnlessAllowed(): void
    {
        $guard = new WebhookUrlGuard();
        self::assertFalse($guard->isAllowed('http://127.0.0.1/x', false));
        self::assertFalse($guard->isAllowed('http://10.1.2.3/x', false));
        self::assertFalse($guard->isAllowed('http://169.254.169.254/latest', false)); // metadata
        self::assertTrue($guard->isAllowed('https://example.com/x', false));
        self::assertTrue($guard->isAllowed('http://127.0.0.1/x', true)); // allowInternal
        self::assertFalse($guard->isAllowed('not-a-url', false));
    }

    public function testRejectsPrivateIPv4Ranges(): void
    {
        $guard = new WebhookUrlGuard();
        self::assertFalse($guard->isAllowed('http://192.168.1.1/hook', false));
        self::assertFalse($guard->isAllowed('http://172.16.0.1/hook', false));
        self::assertFalse($guard->isAllowed('http://10.0.0.1/hook', false));
    }

    public function testRejectsInvalidSchemes(): void
    {
        $guard = new WebhookUrlGuard();
        self::assertFalse($guard->isAllowed('ftp://example.com/hook', false));
        self::assertFalse($guard->isAllowed('file:///etc/passwd', false));
    }

    public function testAllowsPublicHttpAndHttps(): void
    {
        $guard = new WebhookUrlGuard();
        self::assertTrue($guard->isAllowed('https://example.com/hook', false));
        self::assertTrue($guard->isAllowed('http://example.com/hook', false));
    }

    public function testAllowInternalBypassesBlocklist(): void
    {
        $guard = new WebhookUrlGuard();
        self::assertTrue($guard->isAllowed('http://127.0.0.1/x', true));
        self::assertTrue($guard->isAllowed('http://10.1.2.3/x', true));
        self::assertTrue($guard->isAllowed('http://192.168.1.1/x', true));
    }

    public function testRejectsMalformedUrls(): void
    {
        $guard = new WebhookUrlGuard();
        self::assertFalse($guard->isAllowed('', false));
        self::assertFalse($guard->isAllowed('not-a-url', false));
        self::assertFalse($guard->isAllowed('://no-scheme', false));
    }
}
