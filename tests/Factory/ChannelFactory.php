<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Channel;
use App\Enum\Channel\ChannelType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Channel>
 */
final class ChannelFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Channel::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->word(),
            'identifier' => self::faker()->unique()->slug(2),
            'type' => ChannelType::Text,
            'community' => CommunityFactory::new(),
            'private' => false,
        ];
    }

    public function audio(): static
    {
        return $this->with(['type' => ChannelType::Audio]);
    }

    public function private(): static
    {
        return $this->with(['private' => true]);
    }

    public function inCommunity(\App\Entity\Community $community): static
    {
        return $this->with(['community' => $community]);
    }
}
