<?php

declare(strict_types=1);

namespace App\Exception\ChannelSection;

use App\Exception\DomainExceptionInterface;

class CannotDeleteNonEmptySectionException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.channel_section.cannot_delete_non_empty';
    }
}
