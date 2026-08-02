<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Test-env hub decorator. The compiled `test` container is shared between
 * PHPUnit and the e2e web server (APP_ENV=test), so the real-vs-noop choice
 * MUST be made at runtime — a compile-time conditional bakes whichever process
 * compiled the container first, which previously left the e2e server with a
 * silent no-op hub. Under PHPUnit (PHPUNIT_RUNNING set) publishes are dropped
 * to avoid real HTTP; otherwise they delegate to the real hub so SSE works.
 */
final class TestMercureHub implements HubInterface
{
    public function __construct(private readonly HubInterface $inner)
    {
    }

    public function getPublicUrl(): string
    {
        return $this->inner->getPublicUrl();
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return $this->inner->getFactory();
    }

    public function publish(Update $update): string
    {
        if (isset($_SERVER['PHPUNIT_RUNNING'])) {
            return '';
        }

        return $this->inner->publish($update);
    }
}
