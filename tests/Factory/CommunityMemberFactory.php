<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;
use App\Enum\Community\CommunityRole;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CommunityMember>
 */
final class CommunityMemberFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return CommunityMember::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'user' => UserFactory::new(),
            'community' => CommunityFactory::new(),
            'role' => CommunityRole::Member,
        ];
    }

    public static function createForUserAndCommunity(User $user, Community $community): CommunityMember
    {
        return self::createOne(['user' => $user, 'community' => $community]);
    }

    public static function createAdminForCommunity(User $user, Community $community): CommunityMember
    {
        return self::createOne(['user' => $user, 'community' => $community, 'role' => CommunityRole::Admin]);
    }

    public static function createModeratorForCommunity(User $user, Community $community): CommunityMember
    {
        return self::createOne(['user' => $user, 'community' => $community, 'role' => CommunityRole::Moderator]);
    }
}
