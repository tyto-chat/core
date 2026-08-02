<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Community;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Community>
 */
final class CommunityFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return Community::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->words(2, true),
            'identifier' => self::faker()->unique()->slug(2),
            'private' => false,
        ];
    }

    public function private(): static
    {
        return $this->with(['private' => true]);
    }

    public function withIdentifier(string $identifier): static
    {
        return $this->with(['identifier' => $identifier]);
    }
}
