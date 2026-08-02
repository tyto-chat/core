<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Community;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityWelcomeTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminCanSetTextChannelAsWelcome(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('welc-1')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'lobby'])->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-1', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => 'lobby'],
        ]);

        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->getRepository(Community::class)->findOneBy(['identifier' => 'welc-1']);
        self::assertSame('lobby', $reloaded?->getWelcomeChannel()?->getIdentifier());
    }

    public function testAdminCanClearWelcomeChannel(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('welc-clear')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'lobby'])->create();

        // Set via the API first (covers the same code path), then clear.
        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-clear', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => 'lobby'],
        ]);
        self::assertResponseIsSuccessful();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-clear', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => null],
        ]);

        self::assertResponseIsSuccessful();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $em->clear();
        $reloaded = $em->getRepository(Community::class)->findOneBy(['identifier' => 'welc-clear']);
        self::assertNull($reloaded?->getWelcomeChannel());
    }

    public function testAudioChannelRejectedAsWelcome(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('welc-aud')->create();
        ChannelFactory::new()->inCommunity($community)->audio()
            ->with(['identifier' => 'voice'])
            ->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-aud', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => 'voice'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPrivateChannelRejectedAsWelcome(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('welc-priv')->create();
        ChannelFactory::new()->inCommunity($community)->private()
            ->with(['identifier' => 'secret'])
            ->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-priv', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => 'secret'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testCannotSetArchivedChannelAsWelcome(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('wel-arc')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'old-news', 'archivedAt' => new \DateTimeImmutable()])->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/wel-arc', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => 'old-news'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testJoinSkipsWelcomePostWhenChannelArchivedDirectly(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('welc-arc-join')->create();
        $channel = ChannelFactory::new()->inCommunity($community)
            ->with(['identifier' => 'lobby', 'archivedAt' => new \DateTimeImmutable()])
            ->create();
        $community->setWelcomeChannel($channel);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($community);
        $em->flush();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/welc-arc-join/members');
        self::assertResponseStatusCodeSame(204);

        $em->clear();
        $messageCount = (int) $em->createQuery('SELECT COUNT(m) FROM App\Entity\Message m')->getSingleScalarResult();
        self::assertSame(0, $messageCount);
    }

    public function testUnknownChannelRejectedAsWelcome(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('welc-unk')->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-unk', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => 'does-not-exist'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testJoinSucceedsEvenWhenBotIsMissing(): void
    {
        // The defaultBotId server setting defaults to 0 → bot unavailable. The
        // welcome-message side-effect must swallow this silently so joins
        // never fail because of a misconfigured bot.
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('welc-bot-missing')->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'lobby'])->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-bot-missing', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['welcomeChannelIdentifier' => 'lobby'],
        ]);
        self::assertResponseIsSuccessful();

        $this->jsonClient($user)->request('POST', '/api/v1/communities/welc-bot-missing/members');
        self::assertResponseStatusCodeSame(204);
    }

    public function testLocaleCanBeUpdated(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('welc-loc')->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-loc', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['locale' => 'pl'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['locale' => 'pl']);
    }

    public function testLocaleAcceptsAnyValidLanguageEvenWithoutACatalog(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('welc-loc-open')->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-loc-open', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['locale' => 'ja'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['locale' => 'ja']);
    }

    public function testLocaleRejectsGarbage(): void
    {
        $admin = UserFactory::new()->admin()->create();
        CommunityFactory::new()->withIdentifier('welc-loc-bad')->create();

        $this->jsonClient($admin)->request('PATCH', '/api/v1/communities/welc-loc-bad', [
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['locale' => 'not a locale!'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
