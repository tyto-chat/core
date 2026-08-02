<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\CommunityInvite;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CommunityInvite>
 */
final class CommunityInviteFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return CommunityInvite::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'community' => CommunityFactory::new(),
            'token' => bin2hex(random_bytes(16)),
        ];
    }
}
