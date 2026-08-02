<?php

declare(strict_types=1);

namespace App\Dto\Message;

use App\Dto\EntityDtoInterface;
use App\Entity\Message;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateMessageDto implements EntityDtoInterface
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 10000)]
    #[Groups(['message:update'])]
    public string $text = '';

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
