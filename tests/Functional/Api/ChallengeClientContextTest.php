<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChallengeClientContextTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testChallengeWithoutClientContextStillSucceeds(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/challenges', [
            'json' => ['email' => 'plain@example.com'],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testChallengeAcceptsKnownClientContext(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/challenges', [
            'json' => ['email' => 'desktop@example.com', 'client' => 'desktop'],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testChallengeRejectsUnknownClientContext(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/challenges', [
            'json' => ['email' => 'bogus@example.com', 'client' => 'bogus'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
