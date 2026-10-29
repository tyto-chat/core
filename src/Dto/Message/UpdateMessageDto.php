<?php

declare(strict_types=1);

namespace App\Dto\Message;

use App\Dto\EntityDtoInterface;
use App\Entity\Message;
use App\Validator\Constraint\MessageText;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateMessageDto implements EntityDtoInterface
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[MessageText]
    #[Groups(['message:update'])]
    public string $text = '';

    #[Assert\PositiveOrZero]
    #[Groups(['message:update'])]
    public ?int $baseRevisionCount = null;

    public static function getEntityClass(): string
    {
        return Message::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Message);
        $entity->setText($this->text);
    }
}
