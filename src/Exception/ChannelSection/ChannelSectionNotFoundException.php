<?php

declare(strict_types=1);

namespace App\Exception\ChannelSection;

use App\Exception\DomainExceptionInterface;

class ChannelSectionNotFoundException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 404;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.channel_section_not_found';
    }
}
