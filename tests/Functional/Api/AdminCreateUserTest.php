<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\CommunityMember;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Functional tests for POST /api/admin/users (CreateAdminUserProcessor).
 *
 * Covers user + bot provisioning, silent community membership, invite-email
 * mechanics (asserted via ResetPasswordRequest rows, not transport count —
 * SendEmailMessage is async-queued in tests), and all error paths.
 */
class AdminCreateUserTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testCreatesUserAndSendsInvite(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => ['name' => 'Alice', 'email' => 'alice@example.com'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();
        self::assertSame('alice@example.com', $body['email']);
        self::assertFalse($body['isBot']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy(['email' => 'alice@example.com']);
        self::assertNotNull($user);
        self::assertFalse($user->isBot());

        // A ResetPasswordRequest row exists — sendInvitation() was called.
        $request = $em->getRepository(ResetPasswordRequest::class)
            ->findOneBy(['email' => 'alice@example.com']);
        self::assertNotNull($request, 'sendInvitation() should create a ResetPasswordRequest row');
    }

    public function testCreatesBotWithoutInvite(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $response = $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => ['isBot' => true, 'name' => 'Helper'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $body = $response->toArray();
        self::assertTrue($body['isBot']);
        self::assertNotEmpty($body['email'], 'Bot must receive a faker email');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();

        $botEmail = $body['email'];
        $bot = $em->getRepository(User::class)->findOneBy(['email' => $botEmail]);
        self::assertNotNull($bot);
        self::assertTrue($bot->isBot());

        // No ResetPasswordRequest row — no invitation sent for bots.
        $request = $em->getRepository(ResetPasswordRequest::class)->findOneBy(['email' => $botEmail]);
        self::assertNull($request, 'Bots must not get an invitation / ResetPasswordRequest row');
    }

    public function testBotIgnoresCommunityIds(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $community = CommunityFactory::new()->create();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $communityId = $community->getId();

        // communityIds are passed but must be ignored for a bot — bots are
        // never community members.
        $response = $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => ['isBot' => true, 'name' => 'Bot', 'communityIds' => [$communityId]],
        ]);

        self::assertResponseStatusCodeSame(201);
        $botEmail = $response->toArray()['email'];

        $em->clear();
        $bot = $em->getRepository(User::class)->findOneBy(['email' => $botEmail]);
        self::assertNotNull($bot);
        $community = $em->find(\App\Entity\Community::class, $communityId);
        self::assertNotNull($community);

        // NO CommunityMember row for the bot, despite the passed communityIds.
        $membership = $em->getRepository(CommunityMember::class)
            ->findOneBy(['user' => $bot, 'community' => $community]);
        self::assertNull($membership, 'Bot must not be provisioned as a community member');

        // No ResetPasswordRequest for bots.
        $request = $em->getRepository(ResetPasswordRequest::class)->findOneBy(['email' => $botEmail]);
        self::assertNull($request);
    }

    public function testProvisionsUserIntoCommunitiesSilently(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $community = CommunityFactory::new()->create();
        $welcomeChannel = ChannelFactory::new()->inCommunity($community)->create();
        $community->setWelcomeChannel($welcomeChannel);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($community);
        $em->flush();
        $em->clear();

        $communityId = $community->getId();
        $community = $em->find(\App\Entity\Community::class, $communityId);
        self::assertNotNull($community);

        $response = $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => [
                'name' => 'Provisioned User',
                'email' => 'provisioned@example.com',
                'communityIds' => [$communityId],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);

        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'provisioned@example.com']);
        self::assertNotNull($user);

        $membership = $em->getRepository(CommunityMember::class)
            ->findOneBy(['user' => $user, 'community' => $community]);
        self::assertNotNull($membership, 'User should be provisioned as a community member');

        // Welcome channel has no messages (silent provisioning).
        $msgRepo = static::getContainer()->get(MessageRepository::class);
        \assert($msgRepo instanceof MessageRepository);
        self::assertSame(
            0,
            $msgRepo->countForCommunity($community),
            'No welcome message should be posted during silent admin provisioning',
        );

        // The invite IS sent: ResetPasswordRequest row exists.
        $request = $em->getRepository(ResetPasswordRequest::class)
            ->findOneBy(['email' => 'provisioned@example.com']);
        self::assertNotNull($request, 'User invite (ResetPasswordRequest) should still be created');
    }

    public function testDuplicateEmailRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();
        UserFactory::createOne(['email' => 'taken@example.com']);

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => ['name' => 'Dup', 'email' => 'taken@example.com'],
        ]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testUserMissingEmailRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => ['isBot' => false, 'name' => 'NoEmail'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testMissingNameRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => ['email' => 'x@y.com'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testUnknownCommunityRejected(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->plainJsonClient($admin)->request('POST', '/api/v1/admin/users', [
            'json' => ['name' => 'Ghost', 'email' => 'ghost@example.com', 'communityIds' => [999999]],
        ]);

        self::assertResponseStatusCodeSame(404);

        // No user was created (community ids are resolved before user creation).
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'ghost@example.com']);
        self::assertNull($user, 'No user should be persisted when an unknown community id is supplied');
    }

    public function testNonAdminForbidden(): void
    {
        $regularUser = UserFactory::createOne();

        $this->plainJsonClient($regularUser)->request('POST', '/api/v1/admin/users', [
            'json' => ['name' => 'Sneaky', 'email' => 'sneaky@example.com'],
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
