<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use App\Dto\EntityDtoInterface;
use App\Entity\Channel;
use App\Entity\ChannelSection;
use App\Entity\Community;
use App\Enum\Channel\ChannelType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateChannelDto implements EntityDtoInterface
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Groups(['channel:create'])]
        public string $name,
        #[Assert\NotNull]
        #[Groups(['channel:create'])]
        public Community $community,
        #[Groups(['channel:create'])]
        public ?string $description = null,
        #[Groups(['channel:create'])]
        public ?ChannelSection $section = null,
        #[Groups(['channel:create'])]
        public bool $isPrivate = false,
        #[Groups(['channel:create'])]
        public bool $isReadonly = false,
        #[Groups(['channel:create'])]
        public bool $areReadonlyRepliesAllowed = false,
        #[Groups(['channel:create'])]
        public ChannelType $type = ChannelType::Text,
        #[Groups(['channel:create'])]
        public bool $allowAttachments = true,
    ) {
    }

    public static function getEntityClass(): string
    {
        return Channel::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Channel);
        $entity->setName($this->name);
        $entity->setCommunity($this->community);
        $entity->setDescription($this->description);
        $entity->setSection($this->section);
        $entity->setPrivate($this->isPrivate);
        $entity->setReadonly($this->isReadonly);
        $entity->setAreReadonlyRepliesAllowed($this->areReadonlyRepliesAllowed);
        $entity->setType($this->type);
        $entity->setAllowAttachments($this->allowAttachments);
    }
}
