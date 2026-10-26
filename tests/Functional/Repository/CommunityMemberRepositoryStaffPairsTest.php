<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repository;

use App\Repository\CommunityMemberRepository;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommunityMemberRepositoryStaffPairsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testFindStaffSharedPairs(): void
    {
        self::bootKernel();
        $x = UserFactory::createOne();
        $y = UserFactory::createOne();
        $z = UserFactory::createOne();
        $m = UserFactory::createOne();
        $k = CommunityFactory::createOne();
        $l = CommunityFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($x, $k);
        CommunityMemberFactory::createModeratorForCommunity($y, $k);
        CommunityMemberFactory::createForUserAndCommunity($m, $k);
        CommunityMemberFactory::createAdminForCommunity($z, $l);

        $repo = static::getContainer()->get(CommunityMemberRepository::class);
        \assert($repo instanceof CommunityMemberRepository);
        $pairs = $repo->findStaffSharedPairs([(int) $x->getId(), (int) $y->getId(), (int) $z->getId()], [(int) $m->getId()]);

        self::assertSame([(int) $x->getId() => [(int) $m->getId()], (int) $y->getId() => [(int) $m->getId()]], $pairs);
    }

    public function testRegularMemberIsNotStaff(): void
    {
        self::bootKernel();
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $k = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $k);
        CommunityMemberFactory::createForUserAndCommunity($b, $k);

        $repo = static::getContainer()->get(CommunityMemberRepository::class);
        \assert($repo instanceof CommunityMemberRepository);

        self::assertSame([], $repo->findStaffSharedPairs([(int) $a->getId()], [(int) $b->getId()]));
        self::assertSame([], $repo->findStaffSharedPairs([], [(int) $b->getId()]));
    }
}
