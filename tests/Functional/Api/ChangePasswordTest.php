<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChangePasswordTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private const string ENDPOINT = '/api/v1/users/me/change-password';

    public function testUserCanChangeTheirPassword(): void
    {
        $user = UserFactory::new()->withPassword('OldPass1!')->create();

        $this->jsonClient($user)->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => 'OldPass1!',
            'newPassword' => 'NewPass2@',
        ]]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testNewPasswordWorksAfterChange(): void
    {
        $user = UserFactory::new()->withPassword('OldPass1!')->with(['email' => 'pw-change@example.com'])->create();

        $this->jsonClient($user)->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => 'OldPass1!',
            'newPassword' => 'BrandNew99!',
        ]]);
        self::assertResponseStatusCodeSame(204);

        $client = static::createClient();
        $client->request('POST', '/auth', [
            'json' => ['email' => 'pw-change@example.com', 'password' => 'BrandNew99!'],
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('token', $client->getResponse()->toArray());
    }

    public function testChangePasswordRevokesRefreshTokens(): void
    {
        UserFactory::new()->withPassword('OldPass1!')->with(['email' => 'pw-revoke@example.com'])->create();

        $client = static::createClient();
        $login = $client->request('POST', '/auth', [
            'json' => ['email' => 'pw-revoke@example.com', 'password' => 'OldPass1!'],
        ])->toArray();
        $refreshToken = $login['refresh_token'];

        static::createClient()->request('POST', self::ENDPOINT, [
            'headers' => ['Authorization' => 'Bearer '.$login['token'], 'Content-Type' => 'application/ld+json'],
            'json' => ['currentPassword' => 'OldPass1!', 'newPassword' => 'BrandNew99!'],
        ]);
        self::assertResponseStatusCodeSame(204);

        static::createClient()->request('POST', '/token/refresh', [
            'json' => ['refresh_token' => $refreshToken],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testOldPasswordNoLongerWorksAfterChange(): void
    {
        $user = UserFactory::new()->withPassword('OldPass1!')->with(['email' => 'pw-old@example.com'])->create();

        $this->jsonClient($user)->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => 'OldPass1!',
            'newPassword' => 'BrandNew99!',
        ]]);
        self::assertResponseStatusCodeSame(204);

        $client = static::createClient();
        $client->request('POST', '/auth', [
            'json' => ['email' => 'pw-old@example.com', 'password' => 'OldPass1!'],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testWrongCurrentPasswordIsRejected(): void
    {
        $user = UserFactory::new()->withPassword('RealPass1!')->create();

        $this->jsonClient($user)->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => 'WrongPass!',
            'newPassword' => 'NewPass2@',
        ]]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testNewPasswordTooShortIsRejected(): void
    {
        $user = UserFactory::new()->withPassword('OldPass1!')->create();

        $this->jsonClient($user)->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => 'OldPass1!',
            'newPassword' => 'short',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testBlankCurrentPasswordIsRejected(): void
    {
        $user = UserFactory::new()->withPassword('OldPass1!')->create();

        $this->jsonClient($user)->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => '',
            'newPassword' => 'NewPass2@',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testBlankNewPasswordIsRejected(): void
    {
        $user = UserFactory::new()->withPassword('OldPass1!')->create();

        $this->jsonClient($user)->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => 'OldPass1!',
            'newPassword' => '',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnauthenticatedUserCannotChangePassword(): void
    {
        $this->jsonClient()->request('POST', self::ENDPOINT, ['json' => [
            'currentPassword' => 'anything',
            'newPassword' => 'NewPass2@',
        ]]);

        self::assertResponseStatusCodeSame(401);
    }
}
