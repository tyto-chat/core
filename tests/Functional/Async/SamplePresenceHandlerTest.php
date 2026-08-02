<?php

declare(strict_types=1);

namespace App\Tests\Functional\Async;

use App\Async\Handler\SamplePresenceHandler;
use App\Async\SamplePresenceMessage;
use App\Entity\PresenceSample;
use App\Repository\PresenceSampleRepository;
use App\Service\Presence\GuestPresenceServiceInterface;
use App\Service\Presence\PresenceServiceInterface;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Stub\InMemoryGuestPresenceService;
use App\Tests\Stub\InMemoryPresenceService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class SamplePresenceHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testSamplesAllCommunitiesAndPrunes(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $member = UserFactory::createOne();
        $public = CommunityFactory::new()->withIdentifier('sample-pub')->create();
        $private = CommunityFactory::new()->withIdentifier('sample-priv')->private()->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $public);
        CommunityMemberFactory::createForUserAndCommunity($member, $private);

        $presence = $container->get(PresenceServiceInterface::class);
        \assert($presence instanceof InMemoryPresenceService);
        $presence->touch($member);

        $guests = $container->get(GuestPresenceServiceInterface::class);
        \assert($guests instanceof InMemoryGuestPresenceService);
        $guests->touch($public, 'visitor-a');
        $guests->touch($private, 'visitor-x');

        $em = $container->get('doctrine.orm.entity_manager');
        $em->persist(new PresenceSample($public, 0, 0, new \DateTimeImmutable('-120 days')));
        $em->flush();

        $container->get(SamplePresenceHandler::class)(new SamplePresenceMessage());

        $repo = $container->get(PresenceSampleRepository::class);
        $fresh = $repo->findForCommunitySince($public, new \DateTimeImmutable('-1 hour'));
        self::assertCount(1, $fresh);
        self::assertSame(1, $fresh[0]->getMembersOnline());
        self::assertSame(1, $fresh[0]->getGuestsOnline());

        $freshPrivate = $repo->findForCommunitySince($private, new \DateTimeImmutable('-1 hour'));
        self::assertCount(1, $freshPrivate);
        self::assertSame(0, $freshPrivate[0]->getGuestsOnline());

        self::assertCount(2, $repo->findAll());
    }
}
