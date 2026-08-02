<?php

declare(strict_types=1);

namespace App\Dto\Notification;

use App\Dto\EntityDtoInterface;
use App\Entity\Notification;

final readonly class UpdateNotificationDto implements EntityDtoInterface
{
    // Defaulted so a PATCH body omitting isRead cannot 500.
    public function __construct(
        public bool $isRead = false,
    ) {
    }

    public static function getEntityClass(): string
    {
        return Notification::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Notification);
        $entity->setIsRead($this->isRead);
    }
}
