<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\User;
use App\Enum\User\UserRole;
use Symfony\Component\Serializer\Attribute\Groups;

class AdminUserRowDto
{
    #[Groups(['admin_user:read'])]
    public int $id = 0;

    #[Groups(['admin_user:read'])]
    public ?string $email = null;

    #[Groups(['admin_user:read'])]
    public ?string $displayName = null;

    #[Groups(['admin_user:read'])]
    public bool $isAdmin = false;

    #[Groups(['admin_user:read'])]
    public bool $isBot = false;

    #[Groups(['admin_user:read'])]
    public bool $isPendingDeletion = false;

    /** ISO 8601. */
    #[Groups(['admin_user:read'])]
    public ?string $createdAt = null;

    public static function fromUser(User $user): self
    {
        $row = new self();
        $row->id = (int) $user->getId();
        $row->email = $user->getEmail();
        $row->displayName = $user->getProfile()?->getName();
        $row->isAdmin = in_array(UserRole::Admin->value, $user->getRoles(), true);
        $row->isBot = $user->isBot();
        $row->isPendingDeletion = $user->isPendingDeletion();
        $row->createdAt = $user->getCreatedAt()?->format(\DateTimeInterface::ATOM);

        return $row;
    }
}
