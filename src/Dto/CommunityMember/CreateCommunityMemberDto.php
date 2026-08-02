<?php

declare(strict_types=1);

namespace App\Dto\CommunityMember;

use App\Dto\EntityDtoInterface;
use App\Entity\Community;
use App\Entity\CommunityMember;
use App\Entity\User;

readonly class CreateCommunityMemberDto implements EntityDtoInterface
{
    public function __construct(
        public readonly User $user,
        public readonly Community $community,
    ) {
    }

    public static function getEntityClass(): string
    {
        return CommunityMember::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof CommunityMember);
        $entity->setUser($this->user);
        $entity->setCommunity($this->community);
    }
}
