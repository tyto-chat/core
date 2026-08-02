<?php

declare(strict_types=1);

namespace App\Dto\User;

use Symfony\Component\Validator\Constraints as Assert;

readonly class SetEmailNotificationsDto
{
    public function __construct(
        #[Assert\NotNull]
        public ?bool $enabled = null,
    ) {
    }
}
