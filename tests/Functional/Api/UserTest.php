<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Message;
use App\Entity\User;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\MessagePageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use App\Tests\Trait\DisablesEmailValidationTrait;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class UserTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;
    use DisablesEmailValidationTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEmailValidationDisabled();
    }

    public function testRegisterCreatesUser(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'new@example.com',
            'password' => 'securepass',
            'displayName' => 'Test User',
        ]]);

        self::assertResponseStatusCodeSame(201);
        // Email is PII (user:read:self) and the registrant isn't authenticated
        // yet, so it isn't echoed back; the public profile is.
        self::assertJsonContains(['profile' => ['name' => 'Test User']]);
    }

    public function testRegisterIgnoresSubmittedRoles(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'sneaky@example.com',
            'password' => 'securepass',
            'displayName' => 'Sneaky User',
            'roles' => ['ROLE_ADMIN'],
        ]]);

        self::assertResponseStatusCodeSame(201);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'sneaky@example.com']);
        self::assertNotNull($user);
        self::assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testRegisterWithDuplicateEmailReturns422(): void
    {
        UserFactory::createOne(['email' => 'taken@example.com']);

        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'taken@example.com',
            'password' => 'securepass',
            'displayName' => 'Test User',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithShortPasswordReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'new@example.com',
            'password' => 'short',
            'displayName' => 'Test User',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithInvalidEmailReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'notanemail',
            'password' => 'securepass',
            'displayName' => 'Test User',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithBlankEmailReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => '',
            'password' => 'securepass',
            'displayName' => 'Test User',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithPasswordTooLongReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'new@example.com',
            'password' => str_repeat('a', 65),
            'displayName' => 'Test User',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithBlankPasswordReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'new@example.com',
            'password' => '',
            'displayName' => 'Test User',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithoutDisplayNameReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'new@example.com',
            'password' => 'securepass',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithShortDisplayNameReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'new@example.com',
            'password' => 'securepass',
            'displayName' => 'Abe',
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testRegisterWithDisplayNameTooLongReturns422(): void
    {
        $this->jsonClient()->request('POST', '/api/v1/users', ['json' => [
            'email' => 'new@example.com',
            'password' => 'securepass',
            'displayName' => str_repeat('a', 256),
        ]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testMeReturnsCurrentUser(): void
    {
        $user = UserFactory::createOne(['email' => 'me@example.com']);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/me');

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['email' => 'me@example.com']);
    }

    public function testMeReturns401ForAnonymous(): void
    {
        $this->jsonClient()->request('GET', '/api/v1/me');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminCanListUsers(): void
    {
        $admin = UserFactory::new()->admin()->create();
        UserFactory::createMany(3);

        $this->jsonClient($admin)->request('GET', '/api/v1/users');

        self::assertResponseIsSuccessful();
    }

    public function testRegularUserCannotListUsers(): void
    {
        $user = UserFactory::createOne();

        $this->jsonClient($user)->request('GET', '/api/v1/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatedUserCanGetUserById(): void
    {
        $user = UserFactory::createOne();
        $target = UserFactory::createOne(['email' => 'target@example.com']);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/users/'.$target->getId());

        self::assertResponseIsSuccessful();
        // Public profile is exposed, but NOT another member's email (PII).
        self::assertArrayHasKey('profile', $response->toArray());
        self::assertArrayNotHasKey('email', $response->toArray());
    }

    public function testUserSeesOwnEmailViaGetById(): void
    {
        $user = UserFactory::createOne(['email' => 'self@example.com']);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/users/'.$user->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('self@example.com', $response->toArray()['email'] ?? null);
    }

    public function testAdminSeesAnyUserEmail(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne(['email' => 'target@example.com']);

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/users/'.$target->getId());

        self::assertResponseIsSuccessful();
        self::assertSame('target@example.com', $response->toArray()['email'] ?? null);
    }

    public function testAnonymousCannotGetUserById(): void
    {
        $target = UserFactory::createOne();

        $this->jsonClient()->request('GET', '/api/v1/users/'.$target->getId());

        self::assertResponseStatusCodeSame(401);
    }

    public function testAdminCanChangeUserRoles(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['roles' => ['ROLE_ADMIN']],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testRegularUserCannotChangeRoles(): void
    {
        $user = UserFactory::createOne();
        $target = UserFactory::createOne();

        $this->jsonClient($user)->request('PATCH', '/api/v1/users/'.$target->getId(), [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['roles' => ['ROLE_ADMIN']],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDeleteUser(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/users/'.$target->getId());

        self::assertResponseStatusCodeSame(204);
    }

    public function testAdminDeleteAnonymisesUserWithContent(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $channel = ChannelFactory::createOne();
        $page = MessagePageFactory::new()->forChannel($channel)->create();
        $message = MessageFactory::new()->inPage($page)->byUser($target)->create();

        $this->jsonClient($admin)->request('DELETE', '/api/v1/users/'.$target->getId());

        self::assertResponseStatusCodeSame(204);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($target->getId());
        self::assertNotNull($reloaded);
        self::assertStringStartsWith('deleted-', (string) $reloaded->getEmail());
        self::assertTrue($reloaded->isBot());
        self::assertNotNull($em->getRepository(Message::class)->find($message->getId()));
    }

    public function testRegularUserCannotDeleteUser(): void
    {
        $user = UserFactory::createOne();
        $target = UserFactory::createOne();

        $this->jsonClient($user)->request('DELETE', '/api/v1/users/'.$target->getId());

        self::assertResponseStatusCodeSame(403);
    }
}
