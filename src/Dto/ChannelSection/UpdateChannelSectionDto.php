<?php

declare(strict_types=1);

namespace App\Dto\ChannelSection;

use App\Dto\EntityDtoInterface;
use App\Entity\ChannelSection;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateChannelSectionDto implements EntityDtoInterface
{
    #[Assert\Length(min: 3, max: 255)]
    #[Groups(['section:update'])]
    public string $name;

    public static function getEntityClass(): string
    {
        return ChannelSection::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof ChannelSection);
        if (isset($this->name)) {
            $entity->setName($this->name);
        }
    }
}
