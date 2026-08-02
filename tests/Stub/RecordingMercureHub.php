<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * Test-env hub decorator that records every published Update so functional
 * tests can inspect the exact bytes broadcast on the wire (e.g. the
 * annotation-driven API Platform auto-publish on message persist). Recording
 * is gated behind PHPUNIT_RUNNING so the long-lived e2e web server (same
 * APP_ENV=test container) stays a pure pass-through and never accumulates
 * updates in a static buffer. Always delegates to the inner hub.
 */
final class RecordingMercureHub implements HubInterface
{
    /** @var list<Update> */
    private static array $updates = [];

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
            self::$updates[] = $update;
        }

        return $this->inner->publish($update);
    }

    public static function reset(): void
    {
        self::$updates = [];
    }

    /** @return list<Update> */
    public static function updates(): array
    {
        return self::$updates;
    }
}
