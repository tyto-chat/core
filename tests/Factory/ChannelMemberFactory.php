<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Channel;
use App\Entity\ChannelMember;
use App\Entity\User;
use App\Enum\Channel\ChannelRole;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ChannelMember>
 */
final class ChannelMemberFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return ChannelMember::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'user' => UserFactory::new(),
            'channel' => ChannelFactory::new(),
            'role' => ChannelRole::Member,
        ];
    }

    public function moderator(): static
    {
        return $this->with(['role' => ChannelRole::Moderator]);
    }

    public static function createForUserAndChannel(User $user, Channel $channel, ChannelRole $role = ChannelRole::Member): ChannelMember
    {
        return self::createOne(['user' => $user, 'channel' => $channel, 'role' => $role]);
    }
}
