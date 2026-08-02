<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Channel;
use App\Entity\GroupChannelPermission;
use App\Entity\UserGroup;
use App\Enum\Channel\ChannelRole;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<GroupChannelPermission>
 */
final class GroupChannelPermissionFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return GroupChannelPermission::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'userGroup' => UserGroupFactory::new(),
            'channel' => ChannelFactory::new(),
            'role' => ChannelRole::Member,
        ];
    }

    public static function createForGroupAndChannel(UserGroup $group, Channel $channel, ChannelRole $role = ChannelRole::Member): GroupChannelPermission
    {
        return self::createOne(['userGroup' => $group, 'channel' => $channel, 'role' => $role]);
    }
}
