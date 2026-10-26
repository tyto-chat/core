<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\HttpCache;

use PHPUnit\Framework\TestCase;

class CaddyfileContextHashKeyTest extends TestCase
{
    public function testHashLookupIsKeyedPerTokenAndUriWithoutStoringTheToken(): void
    {
        $caddyfile = (string) file_get_contents(__DIR__.'/../../../../docker/frankenphp/Caddyfile');
        self::assertSame(1, preg_match('/^:8888 \{\n(.*?)\n\}/ms', $caddyfile, $block), 'no :8888 block in Caddyfile');

        self::assertSame(1, preg_match('/cache \{\s*key \{(.*?)\}/s', $block[1], $key), 'the :8888 cache handler must define its own key');
        $directives = array_map('trim', explode("\n", trim($key[1])));

        self::assertContains('headers Authorization X-Forwarded-Uri', $directives, 'without these the hash lookup is shared across tokens');
        self::assertContains('hash', $directives, 'without hash the bearer token is stored in the Valkey key');
        self::assertContains('hide', $directives, 'without hide Cache-Status echoes the unhashed key, token included');
    }
}
