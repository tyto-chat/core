<?php

declare(strict_types=1);

namespace App\Dto\Conversation;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

readonly class CreateConversationDto
{
    /**
     * @param int[] $memberUserIds
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Count(min: 1, max: 50)]
        #[Assert\All([new Assert\Type('integer')])]
        #[Groups(['conversation:create'])]
        public array $memberUserIds = [],
    ) {
    }
}
