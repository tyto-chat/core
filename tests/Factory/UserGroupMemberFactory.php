<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\User;
use App\Entity\UserGroup;
use App\Entity\UserGroupMember;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<UserGroupMember>
 */
final class UserGroupMemberFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return UserGroupMember::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'user' => UserFactory::new(),
            'userGroup' => UserGroupFactory::new(),
        ];
    }

    public static function createForUserAndGroup(User $user, UserGroup $group): UserGroupMember
    {
        return self::createOne(['user' => $user, 'userGroup' => $group]);
    }
}
