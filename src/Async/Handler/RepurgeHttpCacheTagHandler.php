<?php

declare(strict_types=1);

namespace App\Async\Handler;

use App\Async\RepurgeHttpCacheTagMessage;
use App\Service\HttpCache\CachePurgerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RepurgeHttpCacheTagHandler
{
    public function __construct(
        private readonly CachePurgerInterface $cachePurger,
    ) {
    }

    public function __invoke(RepurgeHttpCacheTagMessage $message): void
    {
        $this->cachePurger->repurgeTag($message->tag);
    }
}
