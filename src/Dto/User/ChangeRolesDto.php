<?php

declare(strict_types=1);

namespace App\Dto\User;

use App\Dto\EntityDtoInterface;
use App\Entity\User;
use App\Enum\User\UserRole;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeRolesDto implements EntityDtoInterface
{
    /** @param list<string> $roles */
    public function __construct(
        #[Assert\All([
            new Assert\Choice(callback: [UserRole::class, 'values']),
        ])]
        public array $roles = [],
    ) {
    }

    public static function getEntityClass(): string
    {
        return User::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof User);
        $entity->setRoles($this->roles);
    }
}
