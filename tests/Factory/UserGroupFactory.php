<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Community;
use App\Entity\User;
use App\Entity\UserGroup;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<UserGroup>
 */
final class UserGroupFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return UserGroup::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'community' => CommunityFactory::new(),
            'name' => self::faker()->words(2, true),
            'isHidden' => false,
        ];
    }

    public function inCommunity(Community $community): static
    {
        return $this->with(['community' => $community]);
    }

    public function hidden(): static
    {
        return $this->with(['isHidden' => true]);
    }

    public function withOwner(User $owner): static
    {
        return $this->with(['owner' => $owner]);
    }

    /** @param array<string, mixed> $attributes */
    public static function createInCommunity(Community $community, array $attributes = []): UserGroup
    {
        return self::new()->inCommunity($community)->with($attributes)->create();
    }
}
