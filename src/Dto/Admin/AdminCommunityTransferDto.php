<?php

declare(strict_types=1);

namespace App\Dto\Admin;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

class AdminCommunityTransferDto
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Positive]
        #[Groups(['admin_community:write'])]
        public ?int $newAdminUserId = null,

        #[Groups(['admin_community:write'])]
        public bool $demoteOthers = false,
    ) {
    }
}
