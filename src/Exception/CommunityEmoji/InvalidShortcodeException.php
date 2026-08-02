<?php

declare(strict_types=1);

namespace App\Exception\CommunityEmoji;

use App\Exception\DomainExceptionInterface;

class InvalidShortcodeException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.community_emoji.invalid_shortcode';
    }
}
