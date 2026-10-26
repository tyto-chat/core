<?php

declare(strict_types=1);

namespace App\Exception\HttpCache;

final class HttpCachePurgeFailedException extends \RuntimeException
{
    public function __construct(public readonly string $tag)
    {
        parent::__construct(sprintf('HTTP cache purge of "%s" failed.', $tag));
    }
}
