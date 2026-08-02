<?php

declare(strict_types=1);

namespace App\Async;

final class SendWebPushMessage
{
    /**
     * @param array<string, string> $bodyParams translation placeholders, e.g. ['%author%' => 'Ann']
     */
    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $bodyKey,
        public readonly array $bodyParams,
        public readonly string $url,
        public readonly string $tag,
    ) {
    }
}
