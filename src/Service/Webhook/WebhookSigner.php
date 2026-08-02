<?php

declare(strict_types=1);

namespace App\Service\Webhook;

final class WebhookSigner
{
    public function sign(string $secret, string $body, int $timestamp): string
    {
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return sprintf('t=%d,v1=%s', $timestamp, $signature);
    }

    public function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
