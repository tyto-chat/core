<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\Community;
use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use App\Enum\Channel\ChannelRole;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\ChannelMemberFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\UserGroupFactory;
use App\Tests\Factory\UserGroupMemberFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class MembershipResidueTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /** @return array{User, Community, Channel, UserGroup} */
    private function setupMemberWithResidue(string $communityId): array
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($communityId)->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => $communityId.'-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($user, $channel);
        $group = UserGroupFactory::new()->inCommunity($community)->withOwner($user)->create();
        UserGroupMemberFactory::createForUserAndGroup($user, $group);

        return [$user, $community, $channel, $group];
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function assertResidueGone(User $user, UserGroup $group): void
    {
        $em = $this->em();
        $em->clear();
        self::assertSame(0, $em->getRepository(ChannelMember::class)->count(['user' => $user->getId()]));
        self::assertSame(0, $em->getRepository(UserGroupMember::class)->count(['user' => $user->getId()]));
        $freshGroup = $em->getRepository(UserGroup::class)->find($group->getId());
        self::assertInstanceOf(UserGroup::class, $freshGroup);
        self::assertNull($freshGroup->getOwner());
    }

    public function testLeaveRemovesChannelAndGroupResidue(): void
    {
        [$user, $community, , $group] = $this->setupMemberWithResidue('residue-leave');
        $otherAdmin = UserFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($otherAdmin, $community);

        $this->jsonClient($user)->request('DELETE', '/api/v1/communities/residue-leave/members');

        self::assertResponseStatusCodeSame(204);
        $this->assertResidueGone($user, $group);
    }

    public function testBanRemovesChannelAndGroupResidue(): void
    {
        [$target, $community, , $group] = $this->setupMemberWithResidue('residue-ban');
        $admin = UserFactory::createOne();
        CommunityMemberFactory::createAdminForCommunity($admin, $community);

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/residue-ban/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'ban',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $this->assertResidueGone($target, $group);
    }

    public function testStaleChannelMemberRowDoesNotGrantAccess(): void
    {
        $exMember = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('residue-stale')->create();
        $channel = ChannelFactory::new()->inCommunity($community)->private()->with(['identifier' => 'stale-ch'])->create();
        ChannelMemberFactory::createForUserAndChannel($exMember, $channel, ChannelRole::Moderator);

        $this->jsonClient($exMember)->request('GET', '/api/v1/communities/residue-stale/channels/stale-ch');

        self::assertResponseStatusCodeSame(404);
    }
}
