<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use App\Dto\EntityDtoInterface;
use App\Dto\PatchDtoTrait;
use App\Entity\Channel;
use App\Entity\ChannelSection;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateChannelDto implements EntityDtoInterface
{
    use PatchDtoTrait;

    #[Assert\Length(max: 255)]
    #[Groups(['channel:update'])]
    public string $name;

    #[Groups(['channel:update'])]
    public ?string $description;

    #[Groups(['channel:update'])]
    public ?ChannelSection $section;

    #[Groups(['channel:update'])]
    public ?bool $isPrivate;

    #[Groups(['channel:update'])]
    public ?bool $isReadonly;

    #[Groups(['channel:update'])]
    public ?bool $areReadonlyRepliesAllowed;

    #[Groups(['channel:update'])]
    public ?bool $allowAttachments;

    public static function getEntityClass(): string
    {
        return Channel::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Channel);
        if ($this->isProvided('name')) {
            $entity->setName($this->name);
        }
        if ($this->isProvided('description')) {
            $entity->setDescription($this->description);
        }
        if ($this->isProvided('section')) {
            $entity->setSection($this->section);
        }
        if ($this->isProvided('isPrivate')) {
            $entity->setPrivate($this->isPrivate);
        }
        if ($this->isProvided('isReadonly')) {
            $entity->setReadonly($this->isReadonly);
        }
        if ($this->isProvided('areReadonlyRepliesAllowed')) {
            $entity->setAreReadonlyRepliesAllowed($this->areReadonlyRepliesAllowed);
        }
        if ($this->isProvided('allowAttachments')) {
            $entity->setAllowAttachments($this->allowAttachments);
        }
    }
}
