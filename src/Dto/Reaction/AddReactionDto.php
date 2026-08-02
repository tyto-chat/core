<?php

declare(strict_types=1);

namespace App\Dto\Reaction;

use App\Dto\EntityDtoInterface;
use App\Entity\Reaction;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class AddReactionDto implements EntityDtoInterface
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 64)]
        #[Groups(['reaction:create'])]
        public string $emoji,
    ) {
    }

    public static function getEntityClass(): string
    {
        return Reaction::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Reaction);
        $entity->setEmoji($this->emoji);
    }
}
