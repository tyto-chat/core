<?php

declare(strict_types=1);

namespace App\Exception\Channel;

use App\Exception\DomainExceptionInterface;

class ChannelArchivedException extends \DomainException implements DomainExceptionInterface
{
    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return 'exception.channel.archived';
    }
}
