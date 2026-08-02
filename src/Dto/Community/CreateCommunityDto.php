<?php

declare(strict_types=1);

namespace App\Dto\Community;

use App\Dto\EntityDtoInterface;
use App\Entity\Community;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateCommunityDto implements EntityDtoInterface
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 255)]
        #[Groups(['community:create'])]
        public string $name,
        #[Assert\AtLeastOneOf([
            new Assert\IsNull(),
            new Assert\Length(min: 3, max: 255),
        ])]
        #[Groups(['community:create'])]
        public ?string $description = null,
        #[Groups(['community:create'])]
        public bool $isPrivate = false,
        #[Assert\AtLeastOneOf([
            new Assert\IsNull(),
            new Assert\Hostname(requireTld: false),
        ])]
        #[Assert\Length(max: 255)]
        #[Groups(['community:create'])]
        public ?string $hostname = null,
        #[Assert\AtLeastOneOf([
            new Assert\IsNull(),
            new Assert\Regex(
                pattern: '/^#[0-9a-f]{6}$/',
                message: 'accentColor must be null or a lowercase hex color in #rrggbb format.',
            ),
        ])]
        #[Groups(['community:create'])]
        public ?string $accentColor = null,
    ) {
    }

    public static function getEntityClass(): string
    {
        return Community::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Community);
        $entity->setName($this->name);
        $entity->setDescription($this->description);
        $entity->setPrivate($this->isPrivate);
        $entity->setHostname($this->hostname);
        $entity->setAccentColor($this->accentColor);
    }
}
