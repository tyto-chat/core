<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\CommunityEmoji;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<CommunityEmoji>
 */
final class CommunityEmojiFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return CommunityEmoji::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        static $i = 0;
        ++$i;

        return [
            'shortcode' => sprintf(':emoji_%d:', $i),
            'position' => 0,
        ];
    }
}
