<?php

declare(strict_types=1);

namespace App\Dto\ChannelSection;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class ReorderSectionsDto
{
    /**
     * @param list<int> $sections
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Count(min: 1)]
        #[Assert\All([new Assert\Type('integer')])]
        #[Groups(['section_reorder:write'])]
        public array $sections = [],
    ) {
    }
}
