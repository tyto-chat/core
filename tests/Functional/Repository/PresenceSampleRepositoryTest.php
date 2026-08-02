<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Entity\PresenceSample;
use App\Repository\PresenceSampleRepository;
use App\Tests\Factory\CommunityFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class PresenceSampleRepositoryTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testFindForCommunitySinceReturnsAscendingWindow(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $community = CommunityFactory::createOne();

        $em->persist(new PresenceSample($community, 5, 1, new \DateTimeImmutable('-3 days')));
        $em->persist(new PresenceSample($community, 7, 2, new \DateTimeImmutable('-1 hour')));
        $em->persist(new PresenceSample($community, 6, 0, new \DateTimeImmutable('-10 days')));
        $em->flush();

        $repo = static::getContainer()->get(PresenceSampleRepository::class);
        $samples = $repo->findForCommunitySince($community, new \DateTimeImmutable('-7 days'));

        self::assertCount(2, $samples);
        self::assertSame(5, $samples[0]->getMembersOnline());
        self::assertSame(7, $samples[1]->getMembersOnline());
    }

    public function testDeleteOlderThanPrunes(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $community = CommunityFactory::createOne();

        $em->persist(new PresenceSample($community, 1, 0, new \DateTimeImmutable('-100 days')));
        $em->persist(new PresenceSample($community, 2, 0, new \DateTimeImmutable('-1 day')));
        $em->flush();

        $repo = static::getContainer()->get(PresenceSampleRepository::class);
        $deleted = $repo->deleteOlderThan(new \DateTimeImmutable('-90 days'));

        self::assertSame(1, $deleted);
        self::assertCount(1, $repo->findAll());
    }
}
