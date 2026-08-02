<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ResetPasswordTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testRequestPasswordResetReturns204ForKnownEmail(): void
    {
        UserFactory::createOne(['email' => 'reset@example.com']);

        $this->jsonClient()->request('POST', '/api/v1/reset_password', [
            'json' => ['email' => 'reset@example.com'],
        ]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testRequestPasswordResetReturns204ForUnknownEmail(): void
    {
        // Should not expose whether the email exists
        $this->jsonClient()->request('POST', '/api/v1/reset_password', [
            'json' => ['email' => 'nobody@example.com'],
        ]);

        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $em->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testTokenIsHashedAtRest(): void
    {
        UserFactory::createOne(['email' => 'hashed@example.com']);

        $this->jsonClient()->request('POST', '/api/v1/reset_password', [
            'json' => ['email' => 'hashed@example.com'],
        ]);
        self::assertResponseStatusCodeSame(204);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $request = $em->getRepository(ResetPasswordRequest::class)->findOneBy(['email' => 'hashed@example.com']);
        self::assertNotNull($request);
        self::assertNull($request->getPlainToken());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $request->getToken());
    }

    public function testResetPasswordRevokesRefreshTokens(): void
    {
        UserFactory::new()->withPassword('OldPass1!')->create(['email' => 'revoke-reset@example.com']);

        $client = static::createClient();
        $login = $client->request('POST', '/auth', [
            'json' => ['email' => 'revoke-reset@example.com', 'password' => 'OldPass1!'],
        ])->toArray();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = new ResetPasswordRequest();
        $request->setEmail('revoke-reset@example.com');
        $request->setExpiresAt(new \DateTimeImmutable('+15 minutes'));
        $em->persist($request);
        $em->flush();

        $this->jsonClient()->request('POST', '/api/v1/password', [
            'json' => [
                'email' => 'revoke-reset@example.com',
                'token' => $request->getPlainToken(),
                'password' => 'newpassword123',
            ],
        ]);
        self::assertResponseStatusCodeSame(204);

        static::createClient()->request('POST', '/token/refresh', [
            'json' => ['refresh_token' => $login['refresh_token']],
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testResetPasswordWithValidTokenChangesPassword(): void
    {
        UserFactory::createOne(['email' => 'change@example.com']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $request = new ResetPasswordRequest();
        $request->setEmail('change@example.com');
        $request->setExpiresAt(new \DateTimeImmutable('+15 minutes'));
        $em->persist($request);
        $em->flush();

        $this->jsonClient()->request('POST', '/api/v1/password', [
            'json' => [
                'email' => 'change@example.com',
                'token' => $request->getPlainToken(),
                'password' => 'newpassword123',
            ],
        ]);

        self::assertResponseStatusCodeSame(204);
    }

    public function testResetPasswordWithInvalidTokenReturns422(): void
    {
        UserFactory::createOne(['email' => 'safe@example.com']);

        $this->jsonClient()->request('POST', '/api/v1/password', [
            'json' => [
                'email' => 'safe@example.com',
                'token' => 'bad-token-xxx',
                'password' => 'newpassword123',
            ],
        ]);

        // Invalid token rejected; no enumeration — unknown emails have no
        // valid token either, so the response never reveals account existence.
        self::assertResponseStatusCodeSame(422);
    }

    public function testResetPasswordWithInvalidTokenDoesNotChangePassword(): void
    {
        $user = UserFactory::createOne(['email' => 'safe2@example.com']);
        $originalPassword = $user->getPassword();

        $this->jsonClient()->request('POST', '/api/v1/password', [
            'json' => [
                'email' => 'safe2@example.com',
                'token' => 'bad-token-xxx',
                'password' => 'newpassword123',
            ],
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->findOneBy(['email' => 'safe2@example.com']);
        self::assertNotNull($reloaded);
        self::assertSame($originalPassword, $reloaded->getPassword());
    }

    public function testResetPasswordValidation(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/password', [
            'json' => [
                'email' => 'notanemail',
                'token' => '',
                'password' => 'short',
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
