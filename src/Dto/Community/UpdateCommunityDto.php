<?php

declare(strict_types=1);

namespace App\Dto\Community;

use App\Dto\EntityDtoInterface;
use App\Dto\PatchDtoTrait;
use App\Entity\Community;
use App\Enum\Community\BroadcastMentionRole;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateCommunityDto implements EntityDtoInterface
{
    use PatchDtoTrait;

    #[Assert\Length(min: 3, max: 255)]
    #[Groups(['community:update'])]
    public string $name;

    #[Assert\AtLeastOneOf([
        new Assert\IsNull(),
        new Assert\Blank(),
        new Assert\Length(min: 4),
    ])]
    #[Groups(['community:update'])]
    public ?string $description;
    #[Groups(['community:update'])]
    public bool $isPrivate;

    #[Assert\Hostname(requireTld: false)]
    #[Assert\Length(max: 255)]
    #[Groups(['community:update'])]
    public string $hostname;

    #[Assert\AtLeastOneOf([
        new Assert\IsNull(),
        new Assert\Regex(
            pattern: '/^#[0-9a-f]{6}$/',
            message: 'accentColor must be null or a lowercase hex color in #rrggbb format.',
        ),
    ])]
    #[Groups(['community:update'])]
    public ?string $accentColor;

    #[Groups(['community:update'])]
    public BroadcastMentionRole $broadcastMentionMinRole;

    #[Assert\Locale(canonicalize: true)]
    #[Assert\Length(max: 12)]
    #[Groups(['community:update'])]
    public string $locale;

    #[Assert\AtLeastOneOf([
        new Assert\IsNull(),
        new Assert\Length(min: 1, max: 255),
    ])]
    #[Groups(['community:update'])]
    public ?string $welcomeChannelIdentifier;

    public static function getEntityClass(): string
    {
        return Community::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Community);
        if ($this->isProvided('name')) {
            $entity->setName($this->name);
        }
        if ($this->isProvided('isPrivate')) {
            $entity->setPrivate($this->isPrivate);
        }
        if ($this->isProvided('hostname')) {
            $entity->setHostname($this->hostname);
        }
        if ($this->isProvided('description')) {
            $entity->setDescription($this->description);
        }
        if ($this->isProvided('accentColor')) {
            $entity->setAccentColor($this->accentColor);
        }
        if ($this->isProvided('broadcastMentionMinRole')) {
            $entity->setBroadcastMentionMinRole($this->broadcastMentionMinRole);
        }
        if ($this->isProvided('locale')) {
            $entity->setLocale($this->locale);
        }
        // welcomeChannelIdentifier deliberately not applied here — CommunityService::update resolves + validates it.
    }
}
