<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Entity\User;
use App\Entity\UserBlock;
use App\Enum\Channel\ChannelRole;
use App\Exception\User\BlockLimitReachedException;
use App\Exception\User\CannotBlockSelfException;
use App\Exception\User\CannotBlockStaffException;
use App\Exception\User\UserNotFoundException;
use App\Repository\UserBlockRepository;
use App\Service\User\UserBlockService;
use App\Service\User\UserBlockServiceInterface;
use App\Service\UserContextServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class UserBlockServiceTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function service(): UserBlockServiceInterface
    {
        $service = static::getContainer()->get(UserBlockServiceInterface::class);
        \assert($service instanceof UserBlockServiceInterface);

        return $service;
    }

    private function repository(): UserBlockRepository
    {
        $repository = static::getContainer()->get(UserBlockRepository::class);
        \assert($repository instanceof UserBlockRepository);

        return $repository;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $fn
     *
     * @return T
     */
    private function as(User $user, \Closure $fn): mixed
    {
        $context = static::getContainer()->get(UserContextServiceInterface::class);
        \assert($context instanceof UserContextServiceInterface);

        return $context->runAs($user, $fn);
    }

    private function id(User $user): int
    {
        return (int) $user->getId();
    }

    public function testBlockCreatesRowAndIsIdempotent(): void
    {
        $me = UserFactory::createOne();
        $other = UserFactory::createOne();

        $first = $this->as($me, fn () => $this->service()->block($this->id($other)));
        $second = $this->as($me, fn () => $this->service()->block($this->id($other)));

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(1, $this->repository()->countByBlocker($me));
    }

    public function testUnblockAbsentIsNoOp(): void
    {
        $me = UserFactory::createOne();
        $other = UserFactory::createOne();

        $this->as($me, fn () => $this->service()->unblock($this->id($other)));

        self::assertSame(0, $this->repository()->countByBlocker($me));
    }

    public function testUnblockRemovesRow(): void
    {
        $me = UserFactory::createOne();
        $other = UserFactory::createOne();

        $this->as($me, fn () => $this->service()->block($this->id($other)));
        $this->as($me, fn () => $this->service()->unblock($this->id($other)));

        self::assertSame(0, $this->repository()->countByBlocker($me));
    }

    public function testCannotBlockSelf(): void
    {
        $me = UserFactory::createOne();

        try {
            $this->as($me, fn () => $this->service()->block($this->id($me)));
            self::fail('Expected CannotBlockSelfException');
        } catch (CannotBlockSelfException $e) {
            self::assertSame(422, $e->statusCode());
        }
    }

    public function testCannotBlockGlobalAdminOrBot(): void
    {
        $me = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();
        $bot = UserFactory::new()->bot()->create();

        foreach ([$admin, $bot] as $target) {
            try {
                $this->as($me, fn () => $this->service()->block($this->id($target)));
                self::fail('Expected CannotBlockStaffException');
            } catch (CannotBlockStaffException $e) {
                self::assertSame(422, $e->statusCode());
            }
        }
    }

    public function testCannotBlockModeratorOfSharedCommunity(): void
    {
        $me = UserFactory::createOne();
        $mod = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($me, $community);
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        $this->expectException(CannotBlockStaffException::class);
        $this->as($me, fn () => $this->service()->block($this->id($mod)));
    }

    public function testCanBlockModeratorOfUnsharedCommunity(): void
    {
        $me = UserFactory::createOne();
        $mod = UserFactory::createOne();
        CommunityMemberFactory::createModeratorForCommunity($mod, CommunityFactory::createOne());
        CommunityMemberFactory::createForUserAndCommunity($me, CommunityFactory::createOne());

        $block = $this->as($me, fn () => $this->service()->block($this->id($mod)));

        self::assertNotNull($block->getId());
    }

    public function testChannelModeratorIsNotStaff(): void
    {
        $me = UserFactory::createOne();
        $channelMod = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($me, $community);
        CommunityMemberFactory::createForUserAndCommunity($channelMod, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->create();
        ChannelMemberFactory::createForUserAndChannel($channelMod, $channel, ChannelRole::Moderator);

        self::assertFalse($this->service()->isStaffFor($channelMod, $me));

        $block = $this->as($me, fn () => $this->service()->block($this->id($channelMod)));

        self::assertNotNull($block->getId());
        self::assertSame([$this->id($channelMod)], $this->service()->enforcedRelations($me, [$this->id($channelMod)])->blockedIds);
        self::assertSame(
            ['blocked' => true, 'blockable' => true, 'inEffect' => true],
            $this->as($me, fn () => $this->service()->statusForCurrentUser($this->id($channelMod))),
        );
    }

    public function testIsStaffForReflectsRoles(): void
    {
        $me = UserFactory::createOne();
        $mod = UserFactory::createOne();
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($me, $community);
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);

        self::assertTrue($this->service()->isStaffFor($mod, $me));
        self::assertTrue($this->service()->isStaffFor($admin, $me));
        self::assertFalse($this->service()->isStaffFor($me, $mod));
    }

    public function testBlockLimitReached(): void
    {
        $me = UserFactory::createOne();
        $em = $this->em();
        foreach (UserFactory::createMany(UserBlockService::MAX_BLOCKS) as $target) {
            $em->persist((new UserBlock())->setBlocker($me)->setBlocked($target));
        }
        $em->flush();
        $extra = UserFactory::createOne();

        try {
            $this->as($me, fn () => $this->service()->block($this->id($extra)));
            self::fail('Expected BlockLimitReachedException');
        } catch (BlockLimitReachedException $e) {
            self::assertSame(422, $e->statusCode());
        }
    }

    public function testUnknownTargetThrowsUserNotFound(): void
    {
        $me = UserFactory::createOne();

        $this->expectException(UserNotFoundException::class);
        $this->as($me, fn () => $this->service()->block(999999999));
    }

    public function testStaffTargetBlockIsNotEnforcedAndReappliesAfterDemotion(): void
    {
        $me = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($me, $community);
        $membership = CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->as($me, fn () => $this->service()->block($this->id($target)));
        self::assertSame([$this->id($target)], $this->service()->enforcedRelations($me, [$this->id($target)])->blockedIds);

        $membership->setRole(\App\Enum\Community\CommunityRole::Moderator);
        $this->em()->flush();

        self::assertSame([], $this->service()->enforcedRelations($me, [$this->id($target)])->blockedIds);
        $list = $this->as($me, fn () => $this->service()->listForCurrentUser());
        self::assertFalse($list[0]['inEffect']);
        $status = $this->as($me, fn () => $this->service()->statusForCurrentUser($this->id($target)));
        self::assertSame(['blocked' => true, 'blockable' => false, 'inEffect' => false], $status);

        $membership->setRole(\App\Enum\Community\CommunityRole::Member);
        $this->em()->flush();

        self::assertSame([$this->id($target)], $this->service()->enforcedRelations($me, [$this->id($target)])->blockedIds);
        $list = $this->as($me, fn () => $this->service()->listForCurrentUser());
        self::assertTrue($list[0]['inEffect']);
    }

    public function testListIsNewestFirst(): void
    {
        $me = UserFactory::createOne();
        $first = UserFactory::createOne();
        $second = UserFactory::createOne();

        $this->as($me, fn () => $this->service()->block($this->id($first)));
        $this->as($me, fn () => $this->service()->block($this->id($second)));
        $list = $this->as($me, fn () => $this->service()->listForCurrentUser());

        self::assertSame([$this->id($second), $this->id($first)], array_map(fn (array $row): int => (int) $row['block']->getBlocked()->getId(), $list));
    }

    public function testEnforcedRelationsBothDirections(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();

        $this->as($a, fn () => $this->service()->block($this->id($b)));
        $this->as($c, fn () => $this->service()->block($this->id($a)));

        $relations = $this->service()->enforcedRelations($a, [$this->id($b), $this->id($c)]);

        self::assertSame([$this->id($b)], $relations->blockedIds);
        self::assertSame([$this->id($c)], $relations->blockerIds);
        self::assertTrue($relations->hasAnyWith($this->id($b)));
        self::assertTrue($relations->hasAnyWith($this->id($c)));
        self::assertFalse($relations->hasAnyWith(999999));
    }

    public function testFilterRecipientsNotBlockingDropsBlockersOnly(): void
    {
        $actor = UserFactory::createOne();
        $blocker = UserFactory::createOne();
        $neutral = UserFactory::createOne();

        $this->as($blocker, fn () => $this->service()->block($this->id($actor)));

        $kept = $this->service()->filterRecipientsNotBlocking($this->id($actor), [$this->id($blocker), $this->id($neutral)]);

        self::assertSame([$this->id($neutral)], $kept);
    }

    public function testFilterRecipientsKeepsBlockersWhenActorIsStaff(): void
    {
        $blocker = UserFactory::createOne();
        $modActor = UserFactory::createOne();
        $adminActor = UserFactory::new()->admin()->create();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($blocker, $community);
        CommunityMemberFactory::createModeratorForCommunity($modActor, $community);
        $em = $this->em();
        $em->persist((new UserBlock())->setBlocker($blocker)->setBlocked($modActor));
        $em->persist((new UserBlock())->setBlocker($blocker)->setBlocked($adminActor));
        $em->flush();

        self::assertSame([$this->id($blocker)], $this->service()->filterRecipientsNotBlocking($this->id($modActor), [$this->id($blocker)]));
        self::assertSame([$this->id($blocker)], $this->service()->filterRecipientsNotBlocking($this->id($adminActor), [$this->id($blocker)]));
    }

    public function testEnforcedBlockedIdsByBlocker(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $x = UserFactory::createOne();
        $y = UserFactory::createOne();
        $staff = UserFactory::createOne();
        $community = CommunityFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($a, $community);
        $membership = CommunityMemberFactory::createForUserAndCommunity($staff, $community);

        $this->as($a, fn () => $this->service()->block($this->id($x)));
        $this->as($a, fn () => $this->service()->block($this->id($staff)));
        $this->as($b, fn () => $this->service()->block($this->id($y)));
        $membership->setRole(\App\Enum\Community\CommunityRole::Admin);
        $this->em()->flush();

        $map = $this->service()->enforcedBlockedIdsByBlocker([$this->id($a), $this->id($b)]);

        self::assertSame([$this->id($x)], $map[$this->id($a)]);
        self::assertSame([$this->id($y)], $map[$this->id($b)]);
    }

    public function testDeleteAllInvolvingRemovesBothDirections(): void
    {
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $c = UserFactory::createOne();

        $this->as($a, fn () => $this->service()->block($this->id($b)));
        $this->as($c, fn () => $this->service()->block($this->id($a)));
        $this->as($b, fn () => $this->service()->block($this->id($c)));

        $this->service()->deleteAllInvolving($a);

        self::assertSame(0, $this->repository()->countByBlocker($a));
        self::assertSame(0, $this->repository()->countByBlocker($c));
        self::assertSame(1, $this->repository()->countByBlocker($b));
    }
}
