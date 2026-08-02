<?php

declare(strict_types=1);

namespace App\Tests\Unit\Webhook;

use App\Service\Webhook\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookSignerTest extends TestCase
{
    public function testSignsTimestampAndBodyWithHmacSha256(): void
    {
        $signer = new WebhookSigner();
        $sig = $signer->sign('the-secret', '{"a":1}', 1700000000);
        self::assertSame(
            't=1700000000,v1='.hash_hmac('sha256', '1700000000.{"a":1}', 'the-secret'),
            $sig,
        );
    }

    public function testSignatureChangesWithDifferentSecret(): void
    {
        $signer = new WebhookSigner();
        $sig1 = $signer->sign('secret-a', 'body', 1700000000);
        $sig2 = $signer->sign('secret-b', 'body', 1700000000);
        self::assertNotSame($sig1, $sig2);
    }

    public function testSignatureChangesWithDifferentBody(): void
    {
        $signer = new WebhookSigner();
        $sig1 = $signer->sign('secret', 'body-a', 1700000000);
        $sig2 = $signer->sign('secret', 'body-b', 1700000000);
        self::assertNotSame($sig1, $sig2);
    }

    public function testSignatureChangesWithDifferentTimestamp(): void
    {
        $signer = new WebhookSigner();
        $sig1 = $signer->sign('secret', 'body', 1700000000);
        $sig2 = $signer->sign('secret', 'body', 1700000001);
        self::assertNotSame($sig1, $sig2);
    }

    public function testGenerateSecretReturns64HexChars(): void
    {
        $signer = new WebhookSigner();
        $secret = $signer->generateSecret();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function testGeneratedSecretsAreUnique(): void
    {
        $signer = new WebhookSigner();
        $s1 = $signer->generateSecret();
        $s2 = $signer->generateSecret();
        self::assertNotSame($s1, $s2);
    }
}
