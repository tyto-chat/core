<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ProfileTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAuthenticatedUserCanViewAnyProfile(): void
    {
        $viewer = UserFactory::createOne();
        $target = UserFactory::createOne();

        $this->jsonClient($viewer)->request('GET', '/api/v1/profiles/'.$target->getProfile()->getId());

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousCannotViewProfile(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient()->request('GET', '/api/v1/profiles/'.$user->getProfile()->getId());

        self::assertResponseStatusCodeSame(401);
    }

    public function testOwnerCanUpdateProfile(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'New Name'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'New Name']);
    }

    public function testOwnerCanSetOptionalFields(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => [
                'bio' => 'Hello, I build things.',
                'location' => 'Warsaw',
                'website' => 'https://example.com',
                'birthdayMonth' => 6,
                'birthdayDay' => 7,
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'bio' => 'Hello, I build things.',
            'location' => 'Warsaw',
            'website' => 'https://example.com',
            'birthdayMonth' => 6,
            'birthdayDay' => 7,
        ]);
    }

    public function testBirthdayRequiresBothMonthAndDay(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['birthdayMonth' => 6],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidWebsiteRejected(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['website' => 'not-a-url'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNonOwnerCannotUpdateProfile(): void
    {
        $owner = UserFactory::createOne();
        $other = UserFactory::createOne();

        $this->jsonClient($other)->request('PATCH', '/api/v1/profiles/'.$owner->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Hacked'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanUpdateAnyProfile(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Admin Updated'],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testNameTooShortReturns422(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Ab'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNameTooLongReturns422(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => str_repeat('a', 256)],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testExactMinLengthNameIsAccepted(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/profiles/'.$user->getProfile()->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['name' => 'Abby'],
        ]);

        self::assertResponseIsSuccessful();
    }
}
