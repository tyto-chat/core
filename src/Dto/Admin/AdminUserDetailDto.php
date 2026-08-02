<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use App\Entity\User;
use App\Enum\User\UserRole;
use Symfony\Component\Serializer\Attribute\Groups;

class AdminUserDetailDto extends AdminUserRowDto
{
    #[Groups(['admin_user:read'])]
    public int $apiKeyCount = 0;

    #[Groups(['admin_user:read'])]
    public int $pushSubscriptionCount = 0;

    /** @var string[] */
    #[Groups(['admin_user:read'])]
    public array $roles = [];

    #[Groups(['admin_user:read'])]
    public bool $twoFactorEnabled = false;

    public static function fromDetail(User $user, int $apiKeyCount, int $pushSubscriptionCount): self
    {
        $dto = new self();
        $dto->id = (int) $user->getId();
        $dto->email = $user->getEmail();
        $dto->displayName = $user->getProfile()?->getName();
        $dto->isAdmin = in_array(UserRole::Admin->value, $user->getRoles(), true);
        $dto->isBot = $user->isBot();
        $dto->isPendingDeletion = $user->isPendingDeletion();
        $dto->createdAt = $user->getCreatedAt()?->format(\DateTimeInterface::ATOM);
        $dto->apiKeyCount = $apiKeyCount;
        $dto->pushSubscriptionCount = $pushSubscriptionCount;
        $dto->roles = $user->getRoles();
        $dto->twoFactorEnabled = $user->isTwoFactorEnabled();

        return $dto;
    }
}
