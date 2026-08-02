<?php

declare(strict_types=1);

namespace App\Dto\Channel;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ReorderChannelsDto
{
    /**
     * @param list<int> $channels
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('integer')])]
        #[Groups(['channel_reorder:write'])]
        public array $channels = [],
    ) {
    }
}
