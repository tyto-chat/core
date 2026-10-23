<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\ChannelSection;
use App\Entity\Community;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ChannelSection>
 */
final class ChannelSectionFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return ChannelSection::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        static $i = 0;
        ++$i;

        return [
            'name' => sprintf('Section %d', $i),
            'position' => 0,
        ];
    }

    public function inCommunity(Community $community): self
    {
        return $this->with(['community' => $community]);
    }
}
