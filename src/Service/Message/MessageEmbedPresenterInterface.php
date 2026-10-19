<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Dto\Message\MessageEmbedDto;

interface MessageEmbedPresenterInterface
{
    /** Null for anything an anonymous visitor may not see, so private and missing messages look alike. */
    public function present(string $uuid): ?MessageEmbedDto;
}
