<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Repository\UserRepository;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class UserOnboardingTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCompleteStampsOnboardedAt(): void
    {
        $user = UserFactory::createOne();

        $response = $this->jsonClient($user)->request('POST', '/api/v1/me/onboarding/complete');

        self::assertResponseIsSuccessful();
        self::assertNotNull($response->toArray()['onboardedAt']);

        $repo = static::getContainer()->get(UserRepository::class);
        \assert($repo instanceof UserRepository);
        $fresh = $repo->find($user->getId());
        \assert(null !== $fresh);
        self::assertNotNull($fresh->getOnboardedAt());
    }

    public function testCompleteIsIdempotent(): void
    {
        $user = UserFactory::createOne();
        $client = $this->jsonClient($user);

        $first = $client->request('POST', '/api/v1/me/onboarding/complete')->toArray()['onboardedAt'];
        $second = $client->request('POST', '/api/v1/me/onboarding/complete')->toArray()['onboardedAt'];

        self::assertSame($first, $second, 'Re-completing onboarding must keep the original timestamp.');
    }

    public function testRequiresAuth(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/me/onboarding/complete');
        self::assertResponseStatusCodeSame(401);
    }
}
